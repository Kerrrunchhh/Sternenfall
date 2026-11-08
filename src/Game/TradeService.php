<?php

namespace App\Game;

use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\TradePost;
use App\Entity\User;
use App\Repository\AllianceRepository;
use App\Repository\PlanetRepository;
use App\Repository\TradePostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

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
        private PlanetRepository $planets,
        private AllianceRepository $alliances,
        private PlanetService $planetService,
        private Colonizer $colonizer,
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

    public function open(User $trader, string $name, int $galaxy, int $system, int $position): void
    {
        $name = $this->validName($name);
        $this->withEventLock(function () use ($trader, $name, $galaxy, $system, $position): void {
            if (LifeForm::Trader !== $trader->getLifeForm()) {
                throw new GameException('Nur Händler können Handelsstützpunkte eröffnen.');
            }
            if ($this->posts->countByOwner($trader) >= $this->allowedPosts($trader)) {
                throw new GameException(\sprintf('Dein Handelsnetz reicht für %d Stützpunkt(e). Erforsche „Handelsnetz“ weiter.', $this->allowedPosts($trader)));
            }
            if (!$this->colonizer->isValidSlot($galaxy, $system, $position)) {
                throw new GameException('Diese Koordinaten gibt es nicht.');
            }
            if (null !== $this->planets->findAt($galaxy, $system, $position) || null !== $this->posts->findAt($galaxy, $system, $position)) {
                throw new GameException('Ein Handelsstützpunkt braucht einen freien Platz.');
            }

            $this->em->persist(new TradePost($trader, $name, $galaxy, $system, $position, $this->now()));
            $this->em->flush();
        });
    }

    /**
     * @param array<string, float> $prices
     * @param list<LifeForm>       $lifeForms
     * @param list<int>            $allianceIds
     */
    public function configure(TradePost $post, User $actor, string $name, array $prices, array $lifeForms, array $allianceIds): void
    {
        $name = $this->validName($name);
        $this->withEventLock(function () use ($post, $actor, $name, $prices, $lifeForms, $allianceIds): void {
            $this->assertOwner($post, $actor);
            if (!$post->isActive()) {
                throw new GameException('Dieser Stützpunkt ist aufgegeben. Du kannst nur noch die Ware abholen.');
            }
            $post->setName($name);
            $post->setPrices($prices);
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
                throw new GameException('Hol zuerst die restliche Ware ab (Auftrag „Handeln“ mit einer eigenen Flotte).');
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
                throw new GameException('Nur Händler können Flotten von ihren Handelsstützpunkten wegschicken.');
            }
            if (!$fleet->isIdle() || !$fleet->isAt($post->getGalaxy(), $post->getSystem(), $post->getPosition()) || $fleet->getOwner()->isSameAs($actor)) {
                throw new GameException('Diese Flotte steht nicht als fremde Flotte an deinem Stützpunkt.');
            }

            $plan = $this->sendAwayPlan($fleet) ?? throw new GameException('Der Besitzer dieser Flotte hat keinen Heimatplaneten.');
            foreach ($plan['cost']->toArray() as $good => $amount) {
                if ($post->getStock($good) < $amount) {
                    throw new GameException(\sprintf(
                        'Im Lager fehlt Treibstoff oder Proviant: benötigt %s Wasserstoff, %s Nahrung, %s Wasser. Die Flotte bleibt stehen.',
                        number_format($plan['cost']->hydrogen, 0, ',', '.'),
                        number_format($plan['cost']->food, 0, ',', '.'),
                        number_format($plan['cost']->water, 0, ',', '.'),
                    ));
                }
            }
            foreach ($plan['cost']->toArray() as $good => $amount) {
                if ($amount > 0) {
                    $post->addStock($good, -$amount);
                }
            }

            $home = $plan['home'];
            $fleet->depart(FleetMission::Station, $home->getGalaxy(), $home->getSystem(), $home->getPosition(), $this->now(), $plan['seconds']);
            $this->inbox->send($fleet->getOwner(), \sprintf('Flotte „%s“ weggeschickt', $fleet->getLabel()), \sprintf(
                '%s hat deine Flotte vom Handelsstützpunkt %s [%s] zu deinem Heimatplaneten %s [%s] zurückgeschickt.',
                $actor->getUsername(),
                $post->getName(),
                $post->getCoordinates(),
                $home->getName(),
                $home->getCoordinates(),
            ));
            $this->em->flush();
        });
    }

    /**
     * Arrival of a "Beliefern" fleet: cargo and fusionators go into stock.
     * The caller holds the fleet event lock.
     */
    public function supply(TradePost $post, Fleet $fleet): string
    {
        $delivered = [];
        foreach ($fleet->getCargo()->toArray() as $good => $amount) {
            if ($amount > 0) {
                $post->addStock($good, $amount);
                $delivered[] = TradeDeal::label($good).' '.number_format($amount, 0, ',', '.');
            }
        }
        $fleet->setCargo(new Resources());

        $fusionators = $fleet->getShipCount(ShipType::Fusionator);
        if ($fusionators > 0) {
            $post->addStock(TradeDeal::FUSIONATOR, $fusionators);
            $fleet->removeShips(ShipType::Fusionator, $fusionators);
            $delivered[] = 'Fusionator '.$fusionators;
        }

        return [] === $delivered ? 'nichts' : implode(', ', $delivered);
    }

    /**
     * Arrival of a "Handeln" fleet: buys what the order, the stock, the credits and the
     * cargo space allow. The trader is paid; bought fusionators join the fleet.
     * The caller holds the fleet event lock.
     */
    public function buy(TradePost $post, Fleet $fleet): TradeDeal
    {
        $buyer = $fleet->getOwner();
        $trader = $post->getOwner();
        $own = $buyer->isSameAs($trader);
        $deal = new TradeDeal([], 0);

        $this->asPlayer($buyer, function () use ($post, $fleet, $buyer, $own, &$deal): void {
            $capacity = 0;
            foreach ($fleet->getShipList() as $entry) {
                $capacity += $entry['type']->cargo() * $entry['count'];
            }
            $deal = TradeDeal::compute(
                $fleet->getTradeOrder(),
                $post->getStockList(),
                $post->getPrices(),
                $buyer->getCredits(),
                max(0, $capacity - $fleet->getCargo()->total()),
                $own,
            );
            if ($deal->isEmpty()) {
                return;
            }

            $buyer->addCredits(-$deal->price);
            $cargo = $fleet->getCargo();
            foreach ($deal->bought as $good => $amount) {
                $post->addStock($good, -$amount);
                if (TradeDeal::FUSIONATOR === $good) {
                    $fleet->addShips(ShipType::Fusionator, (int) $amount);
                } else {
                    $cargo = $cargo->add(Resources::fromArray([$good => $amount]));
                }
            }
            $fleet->setCargo($cargo);
        });

        // An abandoned post disappears once its owner has collected everything.
        if (!$post->isActive() && $post->isEmpty()) {
            $this->em->remove($post);
        }

        if (!$deal->isEmpty() && $deal->price > 0) {
            $this->asPlayer($trader, static fn () => $trader->addCredits($deal->price));
            $this->inbox->send($trader, \sprintf('Verkauf an %s', $buyer->getUsername()), \sprintf(
                "%s hat an %s [%s] gekauft:\n%s\n\nEinnahme: %s Credits",
                $buyer->getUsername(),
                $post->getName(),
                $post->getCoordinates(),
                self::describe($deal),
                number_format($deal->price, 0, ',', '.'),
            ));
        }

        return $deal;
    }

    public static function describe(TradeDeal $deal): string
    {
        $lines = [];
        foreach ($deal->bought as $good => $amount) {
            $lines[] = \sprintf('  %s: %s', TradeDeal::label($good), number_format($amount, 0, ',', '.'));
        }

        return [] === $lines ? '  nichts' : implode("\n", $lines);
    }

    /** Runs a credit change under the player's lock (all planets synchronized first). */
    private function asPlayer(User $player, callable $operation): void
    {
        $planet = $player->getPlanets()->first();
        if (!$planet instanceof Planet) {
            $operation();
            $this->em->flush();

            return;
        }
        $this->planetService->transaction($planet, static fn () => $operation());
    }

    private function assertOwner(TradePost $post, User $actor): void
    {
        if (!$post->getOwner()->isSameAs($actor)) {
            throw new GameException('Dieser Handelsstützpunkt gehört dir nicht.');
        }
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 40) {
            throw new GameException('Der Name muss 1 bis 40 Zeichen lang sein.');
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
