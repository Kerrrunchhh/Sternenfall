<?php

namespace App\Game;

use App\I18n\Goods;
use App\I18n\Lines;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\TradePost;
use App\Entity\User;
use App\Repository\AllianceRepository;
use App\Repository\TradePostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Component\Lock\LockFactory;
use App\I18n\Num;

use function Symfony\Component\Translation\t;

/**
 * Trade posts of traders: opening, prices and access, and the goods that fleets
 * deliver ("Beliefern") or buy ("Handeln"). Stock changes run under the fleet
 * event lock, credits under the lock of the player they belong to.
 */
final readonly class TradeService
{
    public function __construct(
        private EntityManagerInterface $em,
        private TradePostRepository $posts,
        private AllianceRepository $alliances,
        private PlanetService $planetService,
        private Navigation $navigation,
        private Inbox $inbox,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {
    }

    /** How many trade posts a player may run. */
    public function allowedPosts(User $player): int
    {
        return LifeForm::Trader === $player->getLifeForm() ? $player->getResearchLevel(ResearchType::TradeNetwork) : 0;
    }

    /**
     * Sets up a trade post on one of the trader's colonies (not the home planet). The planet
     * stays a normal planet: built up as usual, its resources are the post's stock, and it can
     * be attacked and invaded – then the post is gone.
     */
    public function open(User $trader, string $name, Planet $planet): void
    {
        $name = $this->validName($name);
        $this->withEventLock(function () use ($trader, $name, $planet): void {
            if (LifeForm::Trader !== $trader->getLifeForm()) {
                throw new GameException(t('Nur Händler können Handelsstützpunkte eröffnen.'));
            }
            if ($this->posts->countByOwner($trader) >= $this->allowedPosts($trader)) {
                throw new GameException(t('Dein Handelsnetz reicht für {value} Stützpunkt(e). Erforsche „Handelsnetz“ weiter.', ['{value}' => $this->allowedPosts($trader)]));
            }
            if (!$planet->getOwner()->isSameAs($trader) || $planet->isIntegrating($this->now())) {
                throw new GameException(t('Einen Handelsstützpunkt richtest du auf einer deiner Kolonien ein.'));
            }
            if ($planet->isHomeworld()) {
                throw new GameException(t('Auf dem Heimatplaneten geht das nicht – wähle eine Kolonie.'));
            }
            if (null !== $this->posts->findAt($planet->getGalaxy(), $planet->getSystem(), $planet->getPosition())) {
                throw new GameException(t('Auf {name} gibt es schon einen Handelsstützpunkt.', ['{name}' => $planet->getName()]));
            }

            $this->em->persist(TradePost::onPlanet($planet, $name, $this->now()));
            $this->em->flush();
        });
    }

    /** Trade posts on planets their owner no longer has (invaded, given away) are gone. */
    public function closeLost(): void
    {
        foreach ($this->posts->findOnLostPlanets() as $post) {
            $planet = $post->getPlanet();
            if (null !== $planet) {
                $this->inbox->send($post->getOwner(), t('Handelsstützpunkt verloren'), t('{planet} [{coords}] gehört dir nicht mehr – dein Handelsstützpunkt „{name}“ dort ist aufgelöst.', ['{planet}' => $planet->getName(), '{coords}' => $planet->getCoordinates(), '{name}' => $post->getName()]));
                $this->em->remove($post);
            }
        }
        $this->em->flush();
    }

    /**
     * Posts from before trade posts were planets stood on free slots: they are dissolved,
     * their stock goes to the owner's home planet (the guild's central exchanges stay).
     */
    public function dissolveOldPosts(): void
    {
        foreach ($this->posts->findOldStandalone() as $post) {
            $owner = $post->getOwner();
            $home = $owner->getPlanets()->findFirst(static fn (int $k, Planet $p): bool => $p->isHomeworld()) ?? $owner->getPlanets()->first();
            $stock = Resources::fromArray($post->getStockList());
            if ($home instanceof Planet && $stock->total() > 0) {
                $this->planetService->transaction($home, static function (Planet $home) use ($stock): void {
                    $home->setResources($home->getResources()->add($stock));
                });
            }
            $this->inbox->send($owner, t('Handelsstützpunkt umgezogen'), t('Handelsstützpunkte stehen jetzt auf einer deiner Kolonien und handeln mit deren Rohstoffen. „{name}“ [{coords}] ist deshalb aufgelöst; das Lager ({goods}) liegt jetzt auf {home}. Richte unter Handel einen neuen Stützpunkt auf einer Kolonie ein.', ['{name}' => $post->getName(), '{coords}' => $post->getCoordinates(), '{goods}' => Goods::summary(array_filter($stock->toArray(), static fn (float $v): bool => $v > 0)), '{home}' => $home instanceof Planet ? $home->getName() : '–']));
            $this->em->remove($post);
        }
        $this->em->flush();
    }

    /**
     * @param array<string, float> $prices     selling prices per good
     * @param list<LifeForm>       $lifeForms
     * @param list<int>            $allianceIds
     * @param array<string, float> $buyPrices  what the trader pays per resource
     * @param array<string, float> $buyLimits  how much of each resource he still buys
     */
    public function configure(TradePost $post, User $actor, string $name, array $prices, array $lifeForms, array $allianceIds, array $buyPrices = [], array $buyLimits = []): void
    {
        $name = $this->validName($name);
        $this->withEventLock(function () use ($post, $actor, $name, $prices, $lifeForms, $allianceIds, $buyPrices, $buyLimits): void {
            $this->assertOwner($post, $actor);
            if (!$post->isActive()) {
                throw new GameException(t('Dieser Stützpunkt ist aufgegeben. Du kannst nur noch die Ware abholen.'));
            }
            $post->setName($name);
            $post->setPrices($prices);
            $post->setPurchase($buyPrices, $buyLimits);
            $post->setAllowedLifeForms($lifeForms);
            $post->setAllowedAlliances([] === $allianceIds ? [] : $this->alliances->findBy(['id' => $allianceIds]));
            $this->em->flush();
        });
    }

    public function close(TradePost $post, User $actor): void
    {
        $this->withEventLock(function () use ($post, $actor): void {
            $this->assertOwner($post, $actor);
            if (!$post->isEmpty()) {
                throw new GameException(t('Hol zuerst die restliche Ware ab (Auftrag „Handeln“ mit einer eigenen Flotte).'));
            }
            $this->em->remove($post);
            $this->em->flush();
        });
    }

    /**
     * Where a foreign fleet at a trade post would be sent and what it costs (one way,
     * paid from the post's stock).
     *
     * @return array{home: Planet, seconds: int, cost: Resources}|null null if the owner has no planet
     */
    public function sendAwayPlan(Fleet $fleet): ?array
    {
        $planets = $fleet->getOwner()->getPlanets();
        $home = $planets->findFirst(static fn (int $key, Planet $p): bool => $p->isHomeworld()) ?? $planets->first();
        if (!$home instanceof Planet) {
            return null;
        }
        $ships = $fleet->getShips();
        $distance = $this->navigation->distance($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition(), $home->getGalaxy(), $home->getSystem(), $home->getPosition());
        $seconds = $this->navigation->flightSeconds($distance, $this->navigation->slowestSpeed($ships, $fleet->getOwner()));
        $cost = $this->navigation->supplies($ships, $seconds, oneWay: true)
            ->add(new Resources(hydrogen: $this->navigation->fuel($ships, $distance, oneWay: true)));

        return ['home' => $home, 'seconds' => $seconds, 'cost' => $cost];
    }

    /**
     * A trader sends a foreign fleet waiting at his trade post back to its owner's home
     * planet. Fuel and supplies come from the post's stock; without enough the fleet stays.
     */
    public function sendAway(TradePost $post, User $actor, Fleet $fleet): void
    {
        $this->withEventLock(function () use ($post, $actor, $fleet): void {
            $this->em->refresh($fleet);
            $this->assertOwner($post, $actor);
            if (LifeForm::Trader !== $actor->getLifeForm() || !$post->isActive()) {
                throw new GameException(t('Nur Händler können Flotten von ihren Handelsstützpunkten wegschicken.'));
            }
            if (!$fleet->isIdle() || !$fleet->isAt($post->getGalaxy(), $post->getSystem(), $post->getPosition()) || $fleet->getOwner()->isSameAs($actor)) {
                throw new GameException(t('Diese Flotte steht nicht als fremde Flotte an deinem Stützpunkt.'));
            }

            $plan = $this->sendAwayPlan($fleet) ?? throw new GameException(t('Der Besitzer dieser Flotte hat keinen Heimatplaneten.'));
            foreach ($plan['cost']->toArray() as $good => $amount) {
                if ($post->getStock($good) < $amount) {
                    throw new GameException(t('Im Lager fehlt Treibstoff oder Proviant: benötigt {hydrogen} Wasserstoff, {food} Nahrung, {water} Wasser. Die Flotte bleibt stehen.', ['{hydrogen}' => new Num($plan['cost']->hydrogen), '{food}' => new Num($plan['cost']->food), '{water}' => new Num($plan['cost']->water)]));
                }
            }
            $this->asPlayer($actor, static function () use ($post, $plan): void {
                foreach ($plan['cost']->toArray() as $good => $amount) {
                    if ($amount > 0) {
                        $post->addStock($good, -$amount);
                    }
                }
            });

            $home = $plan['home'];
            $fleet->depart(FleetMission::Station, $home->getGalaxy(), $home->getSystem(), $home->getPosition(), $this->now(), $plan['seconds']);
            $this->inbox->send($fleet->getOwner(), t('Flotte „{label}“ weggeschickt', ['{label}' => $fleet->getLabel()]), t('{player} hat deine Flotte vom Handelsstützpunkt {name} [{coords}] zu deinem Heimatplaneten {name2} [{coords2}] zurückgeschickt.', ['{player}' => $actor->getUsername(), '{name}' => $post->getName(), '{coords}' => $post->getCoordinates(), '{name2}' => $home->getName(), '{coords2}' => $home->getCoordinates()]));
            $this->em->flush();
        });
    }

    /**
     * Arrival of a "Beliefern" fleet: the cargo goes into stock (fusionators are no trade post goods).
     * The caller holds the fleet event lock.
     */
    public function supply(TradePost $post, Fleet $fleet): TranslatableInterface
    {
        $delivered = array_filter($fleet->getCargo()->toArray(), static fn (float $amount): bool => $amount > 0);
        $this->asPlayer($post->getOwner(), static function () use ($post, $delivered): void {
            foreach ($delivered as $good => $amount) {
                $post->addStock($good, $amount);
            }
        });
        $fleet->setCargo(new Resources());

        return [] === $delivered ? t('nichts') : Goods::summary($delivered);
    }

    /**
     * Trading with a fleet that waits at the trade post: first it sells resources from its
     * cargo to the trader (at his purchase prices, up to the amounts he still buys and his
     * credits), then it buys resources (as far as stock, credits and cargo space allow).
     * The owner of the post stores and collects for free.
     *
     * @param array<string, float> $buy  goods => wanted amount
     * @param array<string, float> $sell resources => offered amount
     */
    public function trade(TradePost $post, Fleet $fleet, User $actor, array $buy, array $sell): TranslatableInterface
    {
        $lines = [];
        $this->withEventLock(function () use ($post, $fleet, $actor, $buy, $sell, &$lines): void {
            $this->em->refresh($post);
            $this->em->refresh($fleet);
            if (!$fleet->getOwner()->isSameAs($actor)) {
                throw new GameException(t('Das ist nicht deine Flotte.'));
            }
            if (!$fleet->isIdle() || !$fleet->isAt($post->getGalaxy(), $post->getSystem(), $post->getPosition())) {
                throw new GameException(t('{label} wartet nicht an diesem Handelsstützpunkt.', ['{label}' => $fleet->getLabel()]));
            }
            if (!$post->isAccessibleTo($actor)) {
                throw new GameException(t('Dieser Handelsstützpunkt ist für dich gesperrt.'));
            }
            $trader = $post->getOwner();
            $own = $actor->isSameAs($trader);

            // 1. Selling to the trader (the owner just stores his goods).
            $sold = [];
            $income = 0.0;
            $this->asPlayer($trader, function () use ($post, $fleet, $sell, $own, $trader, &$sold, &$income): void {
                $cargo = $fleet->getCargo();
                foreach (ResourceType::cases() as $type) {
                    $offered = floor(max(0, (float) ($sell[$type->value] ?? 0)));
                    $amount = min($offered, floor($cargo->get($type)));
                    $price = $own ? 0.0 : $post->getBuyPrice($type->value);
                    if (!$own) {
                        if ($price <= 0) {
                            continue;
                        }
                        $amount = min($amount, $post->getBuyLimit($type->value), floor(($trader->getCredits() - $income) / $price));
                    }
                    if ($amount <= 0) {
                        continue;
                    }
                    $sold[$type->value] = $amount;
                    $income += $amount * $price;
                    $cargo = $cargo->subtract(Resources::fromArray([$type->value => $amount]));
                    $post->addStock($type->value, $amount);
                    if (!$own) {
                        $post->bought($type->value, $amount);
                    }
                }
                $fleet->setCargo($cargo);
                $trader->addCredits(-$income);
            });

            // 2. Buying (with the fresh credits from the sale).
            $deal = new TradeDeal([], 0);
            $this->asPlayer($actor, function () use ($post, $fleet, $actor, $buy, $own, $income, &$deal): void {
                $actor->addCredits($income);
                $capacity = 0;
                foreach ($fleet->getShipList() as $entry) {
                    $capacity += $entry['type']->cargo() * $entry['count'];
                }
                $deal = TradeDeal::compute($buy, $post->getStockList(), $post->getPrices(), $actor->getCredits(), max(0, $capacity - $fleet->getCargo()->total()), $own);
                if ($deal->isEmpty()) {
                    return;
                }
                $actor->addCredits(-$deal->price);
                $cargo = $fleet->getCargo();
                foreach ($deal->bought as $good => $amount) {
                    $cargo = $cargo->add(Resources::fromArray([$good => $amount]));
                }
                $fleet->setCargo($cargo);
            });
            // The goods leave the trader's stock (his planet) under his lock, the price goes to him.
            // Should his planet have less by now (he built something meanwhile), only that is sold.
            if (!$deal->isEmpty()) {
                $missing = [];
                $this->asPlayer($trader, static function () use ($post, $trader, $deal, $own, &$missing): void {
                    foreach ($deal->bought as $good => $amount) {
                        $taken = min($amount, floor($post->getStock($good)));
                        if ($taken < $amount) {
                            $missing[$good] = $amount - $taken;
                        }
                        $post->addStock($good, -$taken);
                    }
                    $refund = $own ? 0.0 : array_sum(array_map(static fn (string $good, float $short): float => $short * $post->getPrice($good), array_keys($missing), $missing));
                    $trader->addCredits($deal->price - $refund);
                });
                if ([] !== $missing) {
                    $this->asPlayer($actor, static function () use ($fleet, $actor, $post, $missing, $own): void {
                        $cargo = $fleet->getCargo();
                        foreach ($missing as $good => $short) {
                            $cargo = $cargo->subtract(Resources::fromArray([$good => $short]));
                            $actor->addCredits($own ? 0.0 : $short * $post->getPrice($good));
                        }
                        $fleet->setCargo($cargo);
                    });
                    $bought = [];
                    foreach ($deal->bought as $good => $amount) {
                        if ($amount - ($missing[$good] ?? 0) > 0) {
                            $bought[$good] = $amount - ($missing[$good] ?? 0);
                        }
                    }
                    $refund = 0.0;
                    foreach ($missing as $good => $short) {
                        $refund += $own ? 0.0 : $short * $post->getPrice($good);
                    }
                    $deal = new TradeDeal($bought, $deal->price - $refund);
                }
            }

            if ([] !== $sold) {
                $lines[] = $own ? t('Eingelagert: {goods}.', ['{goods}' => self::summary($sold)]) : t('Verkauft: {goods} für {income} Credits.', ['{goods}' => self::summary($sold), '{income}' => new Num($income)]);
            }
            if (!$deal->isEmpty()) {
                $lines[] = $own ? t('Abgeholt: {goods}.', ['{goods}' => self::summary($deal->bought)]) : t('Gekauft: {goods} für {price} Credits.', ['{goods}' => self::summary($deal->bought), '{price}' => new Num($deal->price)]);
            }
            if ([] === $lines) {
                throw new GameException(t('Es wurde nichts gehandelt: Lager, Preise, Ankaufsmengen, Credits oder Frachtraum reichten nicht.'));
            }

            if (!$own) {
                $this->inbox->send($trader, t('Handel mit {player} an {name}', ['{player}' => $actor->getUsername(), '{name}' => $post->getName()]), new Lines($lines));
            }
            // An abandoned post disappears once its owner has collected everything.
            if (!$post->isActive() && $post->isEmpty()) {
                $this->em->remove($post);
            }
            $this->em->flush();
        });

        return new Lines($lines, ' ');
    }

    /** @param array<string, float> $goods "Eisen 1.000, Fusionator 2" */
    public static function summary(array $goods): TranslatableInterface
    {
        return Goods::summary($goods);
    }

    /** Runs a credit change under the player's lock (all planets synchronized first). */
    private function asPlayer(User $player, callable $operation): void
    {
        $planet = $player->getPlanets()->first();
        // System accounts (the trade guild) have no economy of their own to keep up to date.
        if (!$planet instanceof Planet || $player->isSystem()) {
            $operation();
            $this->em->flush();

            return;
        }
        $this->planetService->transaction($planet, static fn () => $operation());
    }

    private function assertOwner(TradePost $post, User $actor): void
    {
        if (!$post->getOwner()->isSameAs($actor)) {
            throw new GameException(t('Dieser Handelsstützpunkt gehört dir nicht.'));
        }
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 40) {
            throw new GameException(t('Der Name muss 1 bis 40 Zeichen lang sein.'));
        }

        return $name;
    }

    /** @param callable(): void $operation */
    private function withEventLock(callable $operation): void
    {
        $lock = $this->lockFactory->createLock('fleet_events');
        $lock->acquire(true);
        try {
            $operation();
        } finally {
            $lock->release();
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
