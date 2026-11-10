<?php

namespace App\Game;

use App\I18n\Lines;
use App\Entity\BuildingOrder;
use App\Entity\Planet;
use App\Entity\QueueItem;
use App\Entity\ShipyardOrder;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use App\I18n\Num;

use function Symfony\Component\Translation\t;

/**
 * Entry point for every state change of a planet or its owner. Each operation
 * runs under a per-player lock on freshly loaded data, so concurrent requests
 * cannot double-spend resources or start two constructions.
 */
final readonly class PlanetService
{
    public const int MAX_ORDER_QUANTITY = 100_000;
    /** Defenses have no order limit; only the database column (signed INT) bounds the number. */
    public const int MAX_DEFENSE_ORDER_QUANTITY = 2_147_483_647;

    public static function maxOrderQuantity(ShipType $type): int
    {
        return $type->isStationary() ? self::MAX_DEFENSE_ORDER_QUANTITY : self::MAX_ORDER_QUANTITY;
    }

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
                throw new GameException(t('Die Bauschleife ist voll: ein Gebäude im Bau und eines in Wartestellung.'));
            }

            // Requirements may be fulfilled by upgrades that are already queued.
            $missing = $this->economy->missingRequirements($type->requirements(), $planet, planned: true);
            if ([] !== $missing) {
                throw new GameException(t('Voraussetzungen fehlen: {missing}.', ['{missing}' => new Lines($missing, ', ')]));
            }

            $targetLevel = $planet->getPlannedBuildingLevel($type) + 1;
            // The second upgrade in the queue costs a surcharge; it stays with the order.
            $cost = $this->economy->queuedBuildCost($type, $targetLevel, \count($queue));
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
                throw new GameException(t('Es läuft bereits eine Forschung.'));
            }

            $missing = $this->economy->missingRequirements($type->requirements(), $planet);
            if ([] !== $missing) {
                throw new GameException(t('Voraussetzungen fehlen: {missing}.', ['{missing}' => new Lines($missing, ', ')]));
            }

            $targetLevel = $player->getResearchLevel($type) + 1;
            if (null !== $type->maxLevel() && $targetLevel > $type->maxLevel()) {
                throw new GameException(t('{type} ist bereits auf der höchsten Stufe {maxLevel}.', ['{type}' => t($type->label()), '{maxLevel}' => $type->maxLevel()]));
            }
            $this->pay($planet, $this->economy->researchCost($type, $targetLevel));
            $seconds = $this->economy->researchSeconds($type, $targetLevel, $planet);
            $player->startResearch($type, $targetLevel, $now->modify("+{$seconds} seconds"), $planet);
        });
    }

    /**
     * Renames a planet. Its owner may do so, including a freshly invaded planet
     * during the integration period (it already belongs to the invader).
     */
    public function rename(Planet $planet, User $actor, string $name): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ('' === $name || mb_strlen($name) > 30) {
            throw new GameException(t('Der Planetenname muss 1 bis 30 Zeichen lang sein.'));
        }
        $this->locked($planet, function (Planet $planet) use ($actor, $name): void {
            if (!$planet->getOwner()->isSameAs($actor)) {
                throw new GameException(t('Du kannst nur deine eigenen Planeten umbenennen.'));
            }
            $planet->setName($name);
        });
    }

    /** How long it takes until an abandoned planet is really given up. */
    public const string ABANDON_DELAY = '+48 hours';

    /**
     * Starts abandoning a planet: after 48 hours it is gone with everything on it –
     * buildings, resources and the ships in its hangar. Fleets waiting there stay at the
     * now free slot. The home planet can't be abandoned.
     */
    public function abandon(Planet $planet, User $actor): \DateTimeImmutable
    {
        $at = null;
        $this->locked($planet, function (Planet $planet, \DateTimeImmutable $now) use ($actor, &$at): void {
            if (!$planet->getOwner()->isSameAs($actor)) {
                throw new GameException(t('Du kannst nur deine eigenen Planeten verlassen.'));
            }
            if ($planet->isHomeworld()) {
                throw new GameException(t('Den Heimatplaneten kannst du nicht verlassen.'));
            }
            if ($planet->isBeingAbandoned()) {
                throw new GameException(t('{name} wird bereits verlassen.', ['{name}' => $planet->getName()]));
            }
            $at = $now->modify(self::ABANDON_DELAY);
            $planet->setAbandonAt($at);
        });

        return $at;
    }

    public function stayOn(Planet $planet, User $actor): void
    {
        $this->locked($planet, function (Planet $planet) use ($actor): void {
            if (!$planet->getOwner()->isSameAs($actor) || !$planet->isBeingAbandoned()) {
                throw new GameException(t('Dieser Planet wird nicht verlassen.'));
            }
            $planet->setAbandonAt(null);
        });
    }

    /** Gives up a planet whose 48 hours are over (caller flushes). Returns false if it is not due. */
    public function giveUp(Planet $planet): bool
    {
        $done = false;
        $this->locked($planet, function (Planet $planet, \DateTimeImmutable $now) use (&$done): void {
            if (null === $planet->getAbandonAt() || $planet->getAbandonAt() > $now) {
                return;
            }
            $owner = $planet->getOwner();
            if ($owner->isResearchingOn($planet)) {
                $owner->clearResearch();
            }
            $owner->getPlanets()->removeElement($planet);
            $this->em->remove($planet);
            $done = true;
        });

        return $done;
    }

    /** Abort the running research and refund its full cost to the given planet. */
    public function cancelResearch(Planet $planet): void
    {
        $this->locked($planet, function (Planet $planet): void {
            $player = $planet->getOwner();
            if (!$player->isResearching()) {
                throw new GameException(t('Es läuft keine Forschung.'));
            }

            $cost = $this->economy->researchCost($player->getResearchType(), $player->getResearchTargetLevel());
            $planet->setResources($planet->getResources()->add($cost));
            $player->clearResearch();
        });
    }

    /**
     * Queue ships in the shipyard. Resources and crew are taken immediately.
     * Above 100 % the order costs more (see Economy::shipOrderCost) and is built faster.
     */
    public function orderShips(Planet $planet, ShipType $type, int $quantity, int $percent = 100): void
    {
        Economy::assertBuildPercent($percent);
        $this->locked($planet, function (Planet $planet, \DateTimeImmutable $now) use ($type, $quantity, $percent): void {
            $limit = self::maxOrderQuantity($type);
            if ($quantity < 1 || $quantity > $limit) {
                throw new GameException(t('Bitte gib eine Anzahl zwischen 1 und {limit} an.', ['{limit}' => new Num($limit)]));
            }

            $missing = $this->economy->missingShipRequirements($type, $planet);
            if ([] !== $missing) {
                throw new GameException(t('Voraussetzungen fehlen: {missing}.', ['{missing}' => new Lines($missing, ', ')]));
            }

            $crew = $type->spec()->crew * $quantity;
            if ($crew > floor($planet->getPopulation())) {
                throw new GameException(t('Nicht genügend Bevölkerung für die Besatzung ({crew} benötigt).', ['{crew}' => $crew]));
            }

            $player = $planet->getOwner();
            $unitCost = $this->economy->shipOrderCost($type, $player, $percent);
            $unitCredits = $this->economy->shipOrderCredits($type, $player, $percent);
            if ($unitCredits * $quantity > $player->getCredits()) {
                throw new GameException(t('Nicht genügend Credits ({quantity} benötigt).', ['{quantity}' => new Num($unitCredits * $quantity)]));
            }
            $this->pay($planet, $unitCost->multiply($quantity));
            $player->addCredits(-$unitCredits * $quantity);
            $planet->setPopulation($planet->getPopulation() - $crew);

            $queue = $planet->getQueue(QueueKind::forShip($type));
            $queueIsIdle = [] === $queue;
            $planet->addShipyardOrder(new ShipyardOrder(
                $planet,
                $type,
                $quantity,
                $this->economy->shipOrderSeconds($type, $planet, $percent),
                $queueIsIdle ? $now : null,
                $unitCost,
                $unitCredits,
                $this->nextPosition($queue),
                $percent,
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
            $queue = $planet->getQueue($kind);
            $byId = [];
            foreach ($queue as $item) {
                $byId[$item->getId()] = $item;
            }
            $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
            if (\count($orderIds) !== \count($byId) || [] !== array_diff($orderIds, array_keys($byId))) {
                throw new GameException(t('Die Bauschleife hat sich inzwischen geändert. Bitte lade die Seite neu.'));
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
        } elseif (null !== $head = $planet->getQueue($kind)[0] ?? null) {
            if (!$head->isStarted()) {
                $head->start($now);
            }
        }
    }

    private function findOrder(Planet $planet, QueueKind $kind, int $orderId): BuildingOrder|ShipyardOrder
    {
        foreach ($planet->getQueue($kind) as $order) {
            if ($order->getId() === $orderId) {
                return $order;
            }
        }

        throw new GameException(t('Diesen Auftrag gibt es nicht mehr.'));
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
            throw new GameException(t('Nicht genügend Rohstoffe.'));
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
            // On vacation everything rests: production, queues and research stop at its start.
            $at = $planet->getOwner()->clockAt($now);
            $this->updater->update($planet, $at);
            // Credits are earned on every planet, so keep all of the player's planets up to date.
            foreach ($planet->getOwner()->getPlanets() as $other) {
                if ($other !== $planet) {
                    $this->em->refresh($other);
                    $this->updater->update($other, $at);
                }
            }
            $this->updater->completeResearch($planet->getOwner(), $at);
            $operation($planet, $now);
            $this->em->flush();
            // Alliance tax: added in one statement, so concurrent members don't overwrite each other.
            if (null !== $alliance = $planet->getOwner()->getAlliance()) {
                $tax = $alliance->takePendingTax();
                if ($tax > 0) {
                    $this->em->getConnection()->executeStatement('UPDATE alliance SET treasury = treasury + ? WHERE id = ?', [$tax, $alliance->getId()]);
                }
            }
        } finally {
            $lock->release();
        }
    }
}
