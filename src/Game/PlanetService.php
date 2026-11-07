<?php

namespace App\Game;

use App\Entity\BuildingOrder;
use App\Entity\Planet;
use App\Entity\QueueItem;
use App\Entity\ShipyardOrder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Entry point for every state change of a planet or its owner. Each operation
 * runs under a per-player lock on freshly loaded data, so concurrent requests
 * cannot double-spend resources or start two constructions.
 */
final readonly class PlanetService
{
    public const int MAX_ORDER_QUANTITY = 100_000;

    public function __construct(
        private EntityManagerInterface $em,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        private PlanetUpdater $updater,
        private Economy $economy,
    ) {
    }

    /** Bring resources and constructions up to date. */
    public function synchronize(Planet $planet): void
    {
        $this->locked($planet, static fn () => null);
    }

    /** Adds a building upgrade to the planet's building queue; it is paid immediately. */
    public function build(Planet $planet, BuildingType $type): void
    {
        $this->locked($planet, function (Planet $planet, \DateTimeImmutable $now) use ($type): void {
            $queue = $planet->getBuildingQueue();
            if (\count($queue) >= QueueKind::Buildings->maxLength()) {
                throw new GameException(\sprintf('Die Bauschleife ist voll (höchstens %d Aufträge).', QueueKind::Buildings->maxLength()));
            }

            // Requirements may be fulfilled by upgrades that are already queued.
            $missing = $this->economy->missingRequirements($type->requirements(), $planet, planned: true);
            if ([] !== $missing) {
                throw new GameException('Voraussetzungen fehlen: '.implode(', ', $missing).'.');
            }

            $targetLevel = $planet->getPlannedBuildingLevel($type) + 1;
            $cost = $this->economy->buildCost($type, $targetLevel);
            $this->pay($planet, $cost);

            $order = new BuildingOrder($planet, $type, $targetLevel, $cost, $this->nextPosition($queue));
            $planet->addBuildingOrder($order);
            if ([] === $queue) {
                $this->updater->startBuilding($planet, $order, $now);
            }
        });
    }

    public function research(Planet $planet, ResearchType $type): void
    {
        $this->locked($planet, function (Planet $planet, \DateTimeImmutable $now) use ($type): void {
            $player = $planet->getOwner();
            if ($player->isResearching()) {
                throw new GameException('Es läuft bereits eine Forschung.');
            }

            $missing = $this->economy->missingRequirements($type->requirements(), $planet);
            if ([] !== $missing) {
                throw new GameException('Voraussetzungen fehlen: '.implode(', ', $missing).'.');
            }

            $targetLevel = $player->getResearchLevel($type) + 1;
            $this->pay($planet, $this->economy->researchCost($type, $targetLevel));
            $seconds = $this->economy->researchSeconds($type, $targetLevel, $planet);
            $player->startResearch($type, $targetLevel, $now->modify("+{$seconds} seconds"));
        });
    }

    /** Abort the running research and refund its full cost to the given planet. */
    public function cancelResearch(Planet $planet): void
    {
        $this->locked($planet, function (Planet $planet): void {
            $player = $planet->getOwner();
            if (!$player->isResearching()) {
                throw new GameException('Es läuft keine Forschung.');
            }

            $cost = $this->economy->researchCost($player->getResearchType(), $player->getResearchTargetLevel());
            $planet->setResources($planet->getResources()->add($cost));
            $player->clearResearch();
        });
    }

    /** Queue ships in the shipyard. Resources and crew are taken immediately. */
    public function orderShips(Planet $planet, ShipType $type, int $quantity): void
    {
        $this->locked($planet, function (Planet $planet, \DateTimeImmutable $now) use ($type, $quantity): void {
            if ($quantity < 1 || $quantity > self::MAX_ORDER_QUANTITY) {
                throw new GameException(\sprintf('Bitte gib eine Anzahl zwischen 1 und %s an.', number_format(self::MAX_ORDER_QUANTITY, 0, ',', '.')));
            }

            $missing = $this->economy->missingShipRequirements($type, $planet);
            if ([] !== $missing) {
                throw new GameException('Voraussetzungen fehlen: '.implode(', ', $missing).'.');
            }

            $crew = $type->spec()->crew * $quantity;
            if ($crew > floor($planet->getPopulation())) {
                throw new GameException(\sprintf('Nicht genügend Bevölkerung für die Besatzung (%d benötigt).', $crew));
            }

            $player = $planet->getOwner();
            $unitCost = $this->economy->shipCost($type, $player);
            $unitCredits = $this->economy->shipCredits($type, $player);
            if ($unitCredits * $quantity > $player->getCredits()) {
                throw new GameException(\sprintf('Nicht genügend Credits (%s benötigt).', number_format($unitCredits * $quantity, 0, ',', '.')));
            }
            $this->pay($planet, $unitCost->multiply($quantity));
            $player->addCredits(-$unitCredits * $quantity);
            $planet->setPopulation($planet->getPopulation() - $crew);

            $queue = $planet->getShipyardQueue();
            if (\count($queue) >= QueueKind::Shipyard->maxLength()) {
                throw new GameException(\sprintf('Die Werftschleife ist voll (höchstens %d Aufträge).', QueueKind::Shipyard->maxLength()));
            }
            $queueIsIdle = [] === $queue;
            $planet->addShipyardOrder(new ShipyardOrder(
                $planet,
                $type,
                $quantity,
                $this->economy->shipSeconds($type, $planet),
                $queueIsIdle ? $now : null,
                $unitCost,
                $unitCredits,
                $this->nextPosition($queue),
            ));
        });
    }

    /** Removes an entry from a queue and refunds what was paid for it. */
    public function cancelOrder(Planet $planet, QueueKind $kind, int $orderId): void
    {
        $this->locked($planet, function (Planet $planet, \DateTimeImmutable $now) use ($kind, $orderId): void {
            $order = $this->findOrder($planet, $kind, $orderId);

            if ($order instanceof BuildingOrder) {
                $planet->setResources($planet->getResources()->add($order->getCost()));
                $planet->removeBuildingOrder($order);
            } else {
                $remaining = $order->getRemaining();
                $planet->setResources($planet->getResources()->add($order->getUnitCost()->multiply($remaining)));
                $planet->getOwner()->addCredits($order->getUnitCredits() * $remaining);
                $planet->setPopulation($planet->getPopulation() + $order->getShipType()->spec()->crew * $remaining);
                $planet->removeShipyardOrder($order);
            }

            $this->startHead($planet, $kind, $now);
        });
    }

    /**
     * Puts a queue into the given order. Work on an entry that is moved away from
     * the front is paused and resumed later without losing progress.
     *
     * @param list<int> $orderIds
     */
    public function reorder(Planet $planet, QueueKind $kind, array $orderIds): void
    {
        $this->locked($planet, function (Planet $planet, \DateTimeImmutable $now) use ($kind, $orderIds): void {
            $queue = QueueKind::Buildings === $kind ? $planet->getBuildingQueue() : $planet->getShipyardQueue();
            $byId = [];
            foreach ($queue as $item) {
                $byId[$item->getId()] = $item;
            }
            $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
            if (\count($orderIds) !== \count($byId) || [] !== array_diff($orderIds, array_keys($byId))) {
                throw new GameException('Die Bauschleife hat sich inzwischen geändert. Bitte lade die Seite neu.');
            }

            foreach ($queue as $item) {
                $item->stop($now);
            }
            foreach ($orderIds as $position => $id) {
                $byId[$id]->setPosition($position);
            }

            if (QueueKind::Buildings === $kind) {
                $this->keepLevelsAscending($planet->getBuildingQueue());
            }
            $this->startHead($planet, $kind, $now);
        });
    }

    /**
     * Upgrades of the same building must keep ascending levels in the queue:
     * the earlier entry always takes the lower level (with its cost and progress).
     *
     * @param list<BuildingOrder> $queue
     */
    private function keepLevelsAscending(array $queue): void
    {
        foreach ($queue as $i => $earlier) {
            foreach (\array_slice($queue, $i + 1) as $later) {
                if ($later->getBuildingType() === $earlier->getBuildingType() && $later->getTargetLevel() < $earlier->getTargetLevel()) {
                    $earlier->swapPayload($later);
                }
            }
        }
    }

    /** Makes sure the first entry of a queue is being worked on. */
    private function startHead(Planet $planet, QueueKind $kind, \DateTimeImmutable $now): void
    {
        if (QueueKind::Buildings === $kind) {
            while (null !== $head = $planet->getBuildingQueue()[0] ?? null) {
                if ($head->isStarted() || $this->updater->startBuilding($planet, $head, $now)) {
                    break;
                }
            }
        } elseif (null !== $head = $planet->getShipyardQueue()[0] ?? null) {
            if (!$head->isStarted()) {
                $head->start($now);
            }
        }
    }

    private function findOrder(Planet $planet, QueueKind $kind, int $orderId): BuildingOrder|ShipyardOrder
    {
        $queue = QueueKind::Buildings === $kind ? $planet->getBuildingQueue() : $planet->getShipyardQueue();
        foreach ($queue as $order) {
            if ($order->getId() === $orderId) {
                return $order;
            }
        }

        throw new GameException('Diesen Auftrag gibt es nicht mehr.');
    }

    /** @param list<QueueItem> $queue */
    private function nextPosition(array $queue): int
    {
        return [] === $queue ? 0 : max(array_map(static fn (QueueItem $item): int => $item->getPosition(), $queue)) + 1;
    }

    private function pay(Planet $planet, Resources $cost): void
    {
        $stock = $planet->getResources();
        if (!$stock->covers($cost)) {
            throw new GameException('Nicht genügend Rohstoffe.');
        }
        $planet->setResources($stock->subtract($cost));
    }

    /**
     * Runs an operation on an up-to-date planet under its owner's lock and flushes afterwards.
     *
     * @param callable(Planet, \DateTimeImmutable): void $operation
     */
    public function transaction(Planet $planet, callable $operation): void
    {
        $this->locked($planet, $operation);
    }

    private function locked(Planet $planet, callable $operation): void
    {
        $lock = $this->lockFactory->createLock('player_'.$planet->getOwner()->getId());
        $lock->acquire(true);

        try {
            $this->em->refresh($planet->getOwner());
            $this->em->refresh($planet);
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $this->updater->update($planet, $now);
            // Credits are earned on every planet, so keep all of the player's planets up to date.
            foreach ($planet->getOwner()->getPlanets() as $other) {
                if ($other !== $planet) {
                    $this->em->refresh($other);
                    $this->updater->update($other, $now);
                }
            }
            $this->updater->completeResearch($planet->getOwner(), $now);
            $operation($planet, $now);
            $this->em->flush();
        } finally {
            $lock->release();
        }
    }
}
