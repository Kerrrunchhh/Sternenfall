<?php

namespace App\Game;

use App\Entity\BuildingOrder;
use App\Entity\Planet;
use App\Entity\User;

/**
 * Brings a planet's state up to a point in time: accrues resource production,
 * simulates population growth, consumption and starvation, completes a
 * construction that finished in the meantime and delivers finished ships.
 *
 * Pure state logic, no persistence. Callers are responsible for locking and flushing.
 */
final readonly class PlanetUpdater
{
    /** Population and supplies influence each other, so time is simulated in steps of at most this many hours. */
    private const float STEP_HOURS = 1 / 6;

    public function __construct(private Economy $economy)
    {
    }

    public function update(Planet $planet, \DateTimeImmutable $now): void
    {
        // Work through the building queue: production up to each completion uses the old levels.
        while (null !== $order = $planet->getBuildingQueue()[0] ?? null) {
            if (!$order->isStarted() && !$this->startBuilding($planet, $order, $planet->getResourcesUpdatedAt())) {
                continue; // requirements no longer met: refunded and dropped
            }
            $finishesAt = $order->getFinishesAt();
            if ($finishesAt > $now) {
                break;
            }
            $this->accrue($planet, $finishesAt);
            $planet->setBuildingLevel($order->getBuildingType(), $order->getTargetLevel());
            $planet->removeBuildingOrder($order);

            // The next upgrade starts right when this one is done.
            if (null !== $next = $planet->getBuildingQueue()[0] ?? null) {
                $this->startBuilding($planet, $next, $finishesAt);
            }
        }

        $this->accrue($planet, $now);
        $this->advanceShipyard($planet, $now);
    }

    /**
     * Starts the given building order at $at. If its requirements are not met
     * (e.g. after reordering), it is dropped and refunded.
     */
    public function startBuilding(Planet $planet, BuildingOrder $order, \DateTimeImmutable $at): bool
    {
        if ([] !== $this->economy->missingRequirements($order->getBuildingType()->requirements(), $planet)) {
            $planet->setResources($planet->getResources()->add($order->getCost()));
            $planet->removeBuildingOrder($order);

            return false;
        }
        $order->start($at, $this->economy->buildSeconds($order->getBuildingType(), $order->getTargetLevel(), $planet, $at));

        return true;
    }

    /** Applies the player's research once it is finished. */
    public function completeResearch(User $player, \DateTimeImmutable $now): void
    {
        $finishesAt = $player->getResearchFinishesAt();
        if (null !== $finishesAt && $finishesAt <= $now) {
            $player->setResearchLevel($player->getResearchType(), $player->getResearchTargetLevel());
            $player->clearResearch();
        }
    }

    /**
     * Builds queued ships one after another; the next order starts when the previous one
     * finishes. Ships and defenses are two queues that run in parallel.
     */
    private function advanceShipyard(Planet $planet, \DateTimeImmutable $now): void
    {
        $this->advanceShipQueue($planet, $planet->getShipyardQueue(), $now);
        $this->advanceShipQueue($planet, $planet->getDefenseQueue(), $now);
    }

    /** @param list<\App\Entity\ShipyardOrder> $queue */
    private function advanceShipQueue(Planet $planet, array $queue, \DateTimeImmutable $now): void
    {
        $previousFinishedAt = null;
        foreach ($queue as $order) {
            if (!$order->isStarted()) {
                // Next order begins when the previous one is done; an idle queue head starts now.
                $order->start($previousFinishedAt ?? $now);
            }

            $planet->addShips($order->getShipType(), $order->advance($now));

            if (!$order->isFinished()) {
                break;
            }
            $previousFinishedAt = $order->getStartedAt();
            $planet->removeShipyardOrder($order);
        }
    }

    private function accrue(Planet $planet, \DateTimeImmutable $until): void
    {
        $seconds = $until->getTimestamp() - $planet->getResourcesUpdatedAt()->getTimestamp();
        if ($seconds <= 0) {
            return;
        }

        $production = $this->economy->productionPerHour($planet);
        $capacity = $this->economy->populationCapacity($planet);
        $growth = $this->economy->populationGrowthPerHour($planet);
        $perInhabitant = $this->economy->consumptionPerHour(1);
        $starvation = Economy::STARVATION_RATE * $this->economy->speed();
        $owner = $planet->getOwner();
        $stepStart = $planet->getResourcesUpdatedAt();

        for ($remaining = $seconds / 3600; $remaining > 1e-9; $remaining -= $step) {
            $step = min(self::STEP_HOURS, $remaining);
            // Starter bonus: production, credits and growth run faster during the player's first day.
            $stepEnd = $stepStart->modify('+'.(int) round($step * 3600).' seconds');
            $bonus = $this->economy->starterFactorBetween($owner, $stepStart, $stepEnd);
            $stepStart = $stepEnd;
            $owner->earnCredits($this->economy->creditsPerHour($planet->getPopulation()) * $bonus * $step);
            $stock = $planet->getResources()->add($production->multiply($bonus * $step));
            $population = $planet->getPopulation();

            // How many inhabitants the available food and water can supply during this step.
            $fed = $population;
            if ($perInhabitant->food > 0) {
                $fed = min($fed, $stock->food / ($perInhabitant->food * $step));
            }
            if ($perInhabitant->water > 0) {
                $fed = min($fed, $stock->water / ($perInhabitant->water * $step));
            }

            $stock = $stock->subtract($perInhabitant->multiply($fed * $step));
            $planet->setResources(Resources::fromCallback(static fn (ResourceType $t): float => max(0.0, $stock->get($t))));

            if ($fed < $population) {
                $population = max($fed, $population * (1 - $starvation * $step));
            } elseif ($population > $capacity) {
                // Overcrowded (e.g. after a fusion or when it was blown apart): people leave gradually.
                $population = max($capacity, $population * (1 - $starvation * $step));
            } else {
                $population = min($capacity, $population + $growth * $bonus * $step);
            }
            $planet->setPopulation($population);
        }

        $planet->setResourcesUpdatedAt($until);
    }
}
