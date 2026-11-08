<?php

namespace App\Game;

use App\Entity\Planet;
use App\Repository\PlanetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Welds planets together with a fusionator and blows fusions apart again.
 * Running constructions and research are cancelled (with refund) instead of blocking.
 *
 * Runs under the fleet event lock (fleets may have to be redirected) and then
 * the player lock, the same order FleetService uses, so the two can't deadlock.
 */
final readonly class FusionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlanetService $planets,
        private PlanetRepository $planetRepository,
        private Economy $economy,
        private LockFactory $lockFactory,
        private Inbox $inbox,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The planets a fusion from $top would absorb: own planets directly below,
     * without gaps, up to the fusion capacity of the player's research.
     * Freshly invaded planets (integration period not over) can't be welded.
     *
     * @return list<Planet>
     */
    public function chain(Planet $top, int $fusionators = 1): array
    {
        return $this->scan($top, $fusionators)['chain'];
    }

    /** How many fusionators a chain of planets needs (each welds as many planets as the research allows). */
    public function fusionatorsNeeded(Planet $top, array $chain): int
    {
        $capacity = $this->economy->fusionCapacity($top->getOwner());
        $welded = array_sum(array_map(static fn (Planet $p): int => $p->getFusionSize(), $chain));

        return 0 === $capacity ? 0 : (int) ceil($welded / $capacity);
    }

    /** Why the chain ends where it ends (null if it simply ran out of capacity or system). */
    public function stopReason(Planet $top, int $fusionators = 1): ?string
    {
        return $this->scan($top, $fusionators)['reason'];
    }

    /**
     * Running work that a fusion of these planets would cancel (with refund).
     *
     * @param list<Planet> $planets top planet first
     *
     * @return list<string>
     */
    public function pendingWork(array $planets): array
    {
        $work = [];
        foreach ($planets as $i => $planet) {
            foreach ($planet->getBuildingQueue() as $order) {
                $work[] = \sprintf('Bau von %s Stufe %d auf %s', $order->getBuildingType()->label(), $order->getTargetLevel(), $planet->getName());
            }
            if ($i > 0) {
                foreach ($planet->getShipyardOrders()->toArray() as $order) {
                    $work[] = \sprintf('%d × %s in der Werft von %s', $order->getRemaining(), $order->getShipType()->label(), $planet->getName());
                }
            }
        }
        $player = $planets[0]->getOwner();
        if ($player->isResearching()) {
            $work[] = \sprintf('Forschung an %s Stufe %d', $player->getResearchType()->label(), $player->getResearchTargetLevel());
        }

        return $work;
    }

    /**
     * Fusion by fusionators that just arrived with a fleet. Each fusionator welds
     * as many planets as the research allows; only the ones needed are used up.
     * The caller (fleet processing) already holds the fleet event lock.
     *
     * @return int number of fusionators used up (the rest fly back)
     */
    public function fuseOnArrival(Planet $top, int $fusionators): int
    {
        $used = 0;
        $this->planets->transaction($top, function (Planet $top, \DateTimeImmutable $now) use ($fusionators, &$used): void {
            $used = $this->weld($top, $now, $fusionators);
        });

        return $used;
    }

    /**
     * Validates and performs the fusion; the crews of the used fusionators join the population.
     *
     * @return int fusionators used
     */
    private function weld(Planet $top, \DateTimeImmutable $now, int $fusionators): int
    {
        if ($top->isIntegrating($now)) {
            throw new GameException(\sprintf('%s geht erst am %s in deinen Besitz über und kann bis dahin nicht fusioniert werden.', $top->getName(), $top->getIntegratedAt()->format('d.m.Y H:i')));
        }

        $chain = $this->chain($top, $fusionators);
        if ([] === $chain) {
            throw new GameException($this->stopReason($top, $fusionators) ?? 'Direkt unter diesem Planeten liegt kein eigener Planet, der verschweißt werden kann.');
        }
        $used = $this->fusionatorsNeeded($top, $chain);

        // All planets of the player are already up to date (the transaction synchronizes them).
        $cancelled = $this->pendingWork([$top, ...$chain]);
        $this->cancelResearch($top);
        $this->cancelConstruction($top);
        foreach ($chain as $planet) {
            $this->cancelConstruction($planet);
            $this->cancelShipyard($planet);
        }

        Fusion::merge($top, $chain);
        $top->setPopulation($top->getPopulation() + $used * ShipType::Fusionator->spec()->crew);

        // Fleets keep coordinates, not planets: whatever returns to a welded slot lands on the fused planet.
        foreach ($chain as $planet) {
            $top->getOwner()->getPlanets()->removeElement($planet);
            $this->em->remove($planet);
        }

        $this->inbox->send($top->getOwner(), \sprintf('Fusion von %s [%s]', $top->getName(), $top->getCoordinates()), implode("\n", [
            \sprintf('%d Planeten wurden mit %s verschweißt, dabei wurden %d Fusionator(en) verbraucht.', \count($chain), $top->getName(), $used),
            ...([] === $cancelled ? [] : ['', 'Abgebrochen (Rohstoffe erstattet):', ...array_map(static fn (string $w) => '  '.$w, $cancelled)]),
        ]));

        return $used;
    }

    public function split(Planet $top): void
    {
        $this->withFleetLock(fn () => $this->planets->transaction($top, function (Planet $top, \DateTimeImmutable $now): void {
            if (!$top->isFused()) {
                throw new GameException('Dieser Planet ist keine Fusion.');
            }

            $cost = Fusion::splitCost($top);
            if (!$top->getResources()->covers($cost)) {
                throw new GameException(\sprintf('Zum Sprengen werden %s Wasserstoff benötigt.', number_format($cost->hydrogen, 0, ',', '.')));
            }
            $top->setResources($top->getResources()->subtract($cost));

            // Building levels are redistributed, so a running construction can't continue.
            $this->cancelConstruction($top);

            foreach (Fusion::split($top, $now) as $planet) {
                $this->em->persist($planet);
            }
        }));
    }

    /** @return array{chain: list<Planet>, reason: ?string} */
    private function scan(Planet $top, int $fusionators): array
    {
        $now = $this->clock->now();
        $perFusionator = $this->economy->fusionCapacity($top->getOwner());
        // Each fusionator welds up to the research level; the whole fusion may not grow beyond it either.
        $capacity = max(0, min($perFusionator * $fusionators, $perFusionator - $top->getFusionSize()));
        $chain = [];
        $welded = 0;
        $next = $top->getLastPosition() + 1;
        $reason = null;

        while ($next <= Universe::planetsInSystem($top->getGalaxy(), $top->getSystem())) {
            $planet = $this->planetRepository->findOneBy(['galaxy' => $top->getGalaxy(), 'system' => $top->getSystem(), 'position' => $next]);
            if (null === $planet) {
                $reason = \sprintf('Platz %d ist frei – die Fusion endet dort.', $next);
                break;
            }
            if ($planet->getOwner()->getId() !== $top->getOwner()->getId()) {
                $reason = \sprintf('Platz %d gehört %s – die Fusion endet dort.', $next, $planet->getOwner()->getUsername());
                break;
            }
            if ($planet->isIntegrating($now)) {
                $reason = \sprintf('%s [%s] wurde invadiert und geht erst am %s in deinen Besitz über – die Fusion endet dort.', $planet->getName(), $planet->getCoordinates(), $planet->getIntegratedAt()->format('d.m.Y H:i'));
                break;
            }
            if ($welded + $planet->getFusionSize() > $capacity) {
                $reason = match (true) {
                    0 === $perFusionator => 'Für eine Fusion wird Fusionstechnik benötigt.',
                    $top->getFusionSize() + $welded + $planet->getFusionSize() > $perFusionator => \sprintf('Deine Fusionstechnik (Stufe %d) erlaubt Fusionen aus höchstens %d Planeten – die Fusion endet hier.', $perFusionator, $perFusionator),
                    default => \sprintf('%d Fusionator(en) reichen bei deiner Fusionstechnik für %d Planeten.', $fusionators, $capacity),
                };
                break;
            }
            $chain[] = $planet;
            $welded += $planet->getFusionSize();
            $next = $planet->getLastPosition() + 1;
        }

        return ['chain' => $chain, 'reason' => $reason];
    }

    /** Refunds and removes every queued building upgrade. */
    private function cancelConstruction(Planet $planet): void
    {
        foreach ($planet->getBuildingQueue() as $order) {
            $planet->setResources($planet->getResources()->add($order->getCost()));
            $planet->removeBuildingOrder($order);
        }
    }

    /** Refunds resources and crew of every queued ship. */
    private function cancelShipyard(Planet $planet): void
    {
        foreach ($planet->getShipyardOrders()->toArray() as $order) {
            $remaining = $order->getRemaining();
            $planet->setResources($planet->getResources()->add($order->getUnitCost()->multiply($remaining)));
            $planet->getOwner()->addCredits($order->getUnitCredits() * $remaining);
            $planet->setPopulation($planet->getPopulation() + $order->getShipType()->spec()->crew * $remaining);
            $planet->removeShipyardOrder($order);
        }
    }

    private function cancelResearch(Planet $top): void
    {
        $player = $top->getOwner();
        if ($player->isResearching()) {
            $cost = $this->economy->researchCost($player->getResearchType(), $player->getResearchTargetLevel());
            $top->setResources($top->getResources()->add($cost));
            $player->clearResearch();
        }
    }

    /** @param callable(): void $operation */
    private function withFleetLock(callable $operation): void
    {
        $lock = $this->lockFactory->createLock('fleet_events');
        $lock->acquire(true);
        try {
            $operation();
        } finally {
            $lock->release();
        }
    }
}
