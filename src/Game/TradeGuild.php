<?php

namespace App\Game;

use App\I18n\When;
use App\Entity\FusionatorOffer;
use App\Entity\Planet;
use App\Entity\TradePost;
use App\Entity\TransportRequest;
use App\Entity\User;
use App\I18n\Num;
use App\Repository\FusionatorOfferRepository;
use App\Repository\PlanetRepository;
use App\Repository\TradePostRepository;
use App\Repository\TransportRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;

use function Symfony\Component\Translation\t;

/**
 * The trade guild: an automatic trader for the time without (enough) player traders.
 * In the middle of every galaxy it runs a permanent trade post that buys and sells every
 * resource at fixed prices – a bit worse than their value, but always and in any amount.
 * It sells fusionators (a bit dearer) and delivers transport requests no player trader
 * took. Trade posts can't be attacked, so neither can the guild.
 */
final readonly class TradeGuild
{
    public const string NAME = 'Handelsgilde';
    public const string POST_NAME = 'Zentralbörse';
    /** The system in the middle of every galaxy. */
    public const int SYSTEM = 250;
    /** The galaxy whose post also sells fusionators. */
    public const int FUSIONATOR_GALAXY = 13;
    /** What a resource is worth in credits (roughly what player traders ask). */
    public const array VALUES = ['iron' => 2.0, 'titanium' => 3.0, 'food' => 1.0, 'water' => 1.0, 'hydrogen' => 4.0];
    /** The guild sells this much dearer and buys this much cheaper than the value. */
    public const float MARGIN = 0.25;
    /** Player traders get the first chance: the guild only takes requests open this long. */
    public const string REQUEST_WAIT = '2 hours';

    /** Longest flight of a guild delivery (before game speed): far from the central exchange is no big drawback. */
    public const int MAX_DELIVERY_HOURS = 4;
    /** Stock, purchase limits and credits it is filled up to: practically unlimited. */
    private const float PLENTY = 1e12;
    private const int FUSIONATORS = 1000;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private TradePostRepository $posts,
        private PlanetRepository $planets,
        private FusionatorOfferRepository $offers,
        private TransportRequestRepository $requests,
        private PlanetService $planetService,
        private Navigation $navigation,
        private Inbox $inbox,
        private ClockInterface $clock,
        private ?CacheInterface $cache = null,
    ) {
    }

    /** @return array<string, float> what the guild asks per resource */
    public static function sellPrices(): array
    {
        return array_map(static fn (float $v): float => round($v * (1 + self::MARGIN), 2), self::VALUES);
    }

    /** @return array<string, float> what the guild pays per resource */
    public static function buyPrices(): array
    {
        return array_map(static fn (float $v): float => round($v * (1 - self::MARGIN), 2), self::VALUES);
    }

    /** Price of one fusionator: a bit above what player traders ask (fixed). */
    public const float FUSIONATOR_PRICE = 250000.0;

    public static function fusionatorPrice(): float
    {
        return self::FUSIONATOR_PRICE;
    }

    public function isGuild(User $user): bool
    {
        return $user->isSystem() && self::NAME === $user->getUsername();
    }

    /** Runs the guild: posts and offer in place (checked once an hour), requests delivered. */
    public function tick(): void
    {
        $now = $this->now();
        $setUp = function (): bool {
            $this->ensure();

            return true;
        };
        null === $this->cache ? $setUp() : $this->cache->get('trade_guild_'.$now->format('YmdH'), static fn () => $setUp());
        $this->serveRequests($now);
    }

    /** The guild, its trade posts (one per galaxy) and its fusionator offer – created or filled up. */
    public function ensure(): User
    {
        $guild = $this->guild();
        $guild->addCredits(max(0, self::PLENTY - $guild->getCredits()));
        $now = $this->now();
        foreach (range(1, Universe::GALAXIES) as $galaxy) {
            // The whole central system is one fusion of the guild; the exchange stands on its top slot.
            $planet = $this->systemFusion($guild, $galaxy, $now);
            $post = $this->postIn($galaxy);
            if (null !== $post && null !== $planet && $post->getPosition() !== $planet->getPosition()) {
                $this->em->remove($post);
                $this->em->flush();
                $post = null;
            }
            $post ??= $this->openPost($guild, $galaxy, $now, $planet?->getPosition());
            if (null === $post) {
                continue; // no free slot in the middle of this galaxy
            }
            $post->setPrices(self::sellPrices());
            $post->setPurchase(self::buyPrices(), array_fill_keys(array_keys(self::VALUES), self::PLENTY));
            foreach (array_keys(self::VALUES) as $resource) {
                $post->addStock($resource, max(0, self::PLENTY - $post->getStock($resource)));
            }
            if (self::FUSIONATOR_GALAXY === $galaxy) {
                $offer = $this->offers->findOneBy(['seller' => $guild]);
                if (null === $offer) {
                    $this->em->persist(new FusionatorOffer($guild, $post, self::FUSIONATORS, self::fusionatorPrice(), [], [], $now));
                } else {
                    $offer->restock(self::FUSIONATORS);
                    $offer->setPrice(self::fusionatorPrice());
                }
            }
        }
        $this->em->flush();

        return $guild;
    }

    /** The guild's trade post in a galaxy, if there is one. */
    /** The flight of a guild delivery, cut to MAX_DELIVERY_HOURS (sped up like everything by the game speed). */
    public static function deliverySeconds(int $flightSeconds, float $gameSpeed): int
    {
        return min($flightSeconds, (int) round(self::MAX_DELIVERY_HOURS * 3600 / max(0.001, $gameSpeed)));
    }

    public function postIn(int $galaxy): ?TradePost
    {
        $guild = $this->users->findOneBy(['system' => true, 'username' => self::NAME]);
        if (null === $guild) {
            return null;
        }
        foreach ($this->posts->findBy(['owner' => $guild]) as $post) {
            if ($post->getGalaxy() === $galaxy) {
                return $post;
            }
        }

        return null;
    }

    /**
     * Takes transport requests no player trader took within REQUEST_WAIT and delivers the
     * ones whose time has come. The goods come from the post in the planet's galaxy.
     */
    public function serveRequests(\DateTimeImmutable $now): void
    {
        $guild = null;
        foreach ($this->requests->findOpen() as $request) {
            if ($request->getCreatedAt() > $now->modify('-'.self::REQUEST_WAIT)) {
                continue;
            }
            $guild ??= $this->ensure();
            $post = $this->postIn($request->getGalaxy());
            $seconds = null === $post ? 3600 : self::deliverySeconds($this->navigation->flightSeconds(
                $this->navigation->distance($post->getGalaxy(), $post->getSystem(), $post->getPosition(), $request->getGalaxy(), $request->getSystem(), $request->getPosition()),
                ShipType::LargeFreighter->spec()->speed,
            ), $this->navigation->gameSpeed());
            $request->assignToGuild($guild, $now->modify('+'.$seconds.' seconds'));
            $this->inbox->send($request->getRequester(), t('Die Handelsgilde liefert'), t('Für dein Transportgesuch an {planet} [{coords}] hat sich kein Händler gefunden – die Handelsgilde liefert: {goods}. Ankunft {arrival}.', ['{planet}' => $request->getPlanetName(), '{coords}' => $request->getCoordinates(), '{goods}' => \App\I18n\Goods::summary($request->getGoods()->toArray()), '{arrival}' => new When($request->getGuildDeliveryAt(), 'd.m. H:i')]));
        }

        foreach ($this->requests->findBy(['status' => TransportRequest::ASSIGNED, 'fleet' => null]) as $request) {
            $at = $request->getGuildDeliveryAt();
            if (null === $at || $at > $now) {
                continue;
            }
            $planet = $this->planets->findAt($request->getGalaxy(), $request->getSystem(), $request->getPosition());
            $requester = $request->getRequester();
            if (null === $planet || !$planet->getOwner()->isSameAs($requester)) {
                // The planet is gone or no longer his: no delivery, the price goes back.
                $request->close(TransportRequest::CANCELLED, $now);
                $this->em->getConnection()->executeStatement('UPDATE `user` SET credits = credits + ? WHERE id = ?', [$request->getOffer(), $requester->getId()]);
                $this->inbox->send($requester, t('Lieferung der Handelsgilde gescheitert'), t('{planet} [{coords}] gehört dir nicht mehr. Die Handelsgilde hat nicht geliefert, du bekommst {offer} Credits zurück.', ['{planet}' => $request->getPlanetName(), '{coords}' => $request->getCoordinates(), '{offer}' => new Num($request->getOffer())]));
                continue;
            }
            $goods = $request->getGoods();
            $this->planetService->transaction($planet, static function (Planet $p) use ($goods): void {
                $p->setResources($p->getResources()->add($goods));
            });
            $request->close(TransportRequest::DONE, $now);
            $this->inbox->send($requester, t('Lieferung angekommen'), t('Die Handelsgilde hat dein Transportgesuch für {planet} [{coords}] erfüllt.', ['{planet}' => $request->getPlanetName(), '{coords}' => $request->getCoordinates()]));
        }
        $this->em->flush();
    }

    private function guild(): User
    {
        $guild = $this->users->findOneBy(['system' => true, 'username' => self::NAME]);
        if (null === $guild) {
            $guild = new User(self::NAME, $this->now());
            $guild->setPassword('!'); // no password hash matches: nobody can log in
            $guild->markSystem();
            $guild->changeLifeForm(LifeForm::Trader, $this->now());
            $this->em->persist($guild);
            $this->em->flush();
        }

        return $guild;
    }

    /**
     * The guild's fusion over the central system: all its slots – or, should players already
     * live there, the largest run of free slots. Created once; nobody can attack or settle it.
     */
    private function systemFusion(User $guild, int $galaxy, \DateTimeImmutable $now): ?Planet
    {
        foreach ($this->planets->findBy(['owner' => $guild, 'galaxy' => $galaxy, 'system' => self::SYSTEM]) as $planet) {
            return $planet;
        }
        $slots = Universe::planetsInSystem($galaxy, self::SYSTEM);
        [$bestStart, $bestLength, $start] = [0, 0, null];
        for ($position = 1; $position <= $slots + 1; ++$position) {
            $free = $position <= $slots && null === $this->planets->findAt($galaxy, self::SYSTEM, $position);
            if ($free) {
                $start ??= $position;
                continue;
            }
            if (null !== $start && $position - $start > $bestLength) {
                [$bestStart, $bestLength] = [$start, $position - $start];
            }
            $start = null;
        }
        if (0 === $bestLength) {
            return null;
        }
        $planet = new Planet($guild, self::POST_NAME, $galaxy, self::SYSTEM, $bestStart, $now);
        $planet->spanTo($bestStart + $bestLength - 1, self::POST_NAME);
        $this->em->persist($planet);
        $this->em->flush();

        return $planet;
    }

    /** A new post in the middle of the galaxy: on the guild's fusion, else on the first free slot. */
    private function openPost(User $guild, int $galaxy, \DateTimeImmutable $now, ?int $at = null): ?TradePost
    {
        if (null !== $at) {
            $post = new TradePost($guild, self::POST_NAME, $galaxy, self::SYSTEM, $at, $now);
            $this->em->persist($post);

            return $post;
        }
        for ($position = 1; $position <= Universe::planetsInSystem($galaxy, self::SYSTEM); ++$position) {
            if (null === $this->planets->findAt($galaxy, self::SYSTEM, $position) && null === $this->posts->findAt($galaxy, self::SYSTEM, $position)) {
                $post = new TradePost($guild, self::POST_NAME, $galaxy, self::SYSTEM, $position, $now);
                $this->em->persist($post);

                return $post;
            }
        }

        return null;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
