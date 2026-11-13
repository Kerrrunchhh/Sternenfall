<?php

namespace App\Game;

use App\I18n\When;
use App\Entity\Planet;
use App\Repository\FleetRepository;
use App\Repository\PlanetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use App\I18n\Num;
use App\I18n\Lines;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

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
        private FleetRepository $fleets,
        private Economy $economy,
        private LockFactory $lockFactory,
        private Inbox $inbox,
        private ClockInterface $clock,
    ) {
    }

    /** The planet the target would be welded onto: the one covering the slot directly above it. */
    public function above(Planet $target): ?Planet
    {
        foreach ($this->planetRepository->findInSystem($target->getGalaxy(), $target->getSystem()) as $planet) {
            if ($planet->covers($target->getPosition() - 1)) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * Whether a fusionator at $target can weld it onto the own planet directly above.
     *
     * @return array{top: ?Planet, reason: ?string} the planet it would join, or why not
     */
    public function plan(Planet $target): array
    {
        $now = $this->clock->now();
        $player = $target->getOwner();
        $capacity = $this->economy->fusionCapacity($player);
        $top = $this->above($target);
        $reason = match (true) {
            1 === $target->getPosition() => 'Über diesem Planeten liegt kein Platz mehr – angeschweißt wird immer an den Planeten direkt darüber.',
            null === $top => t('Platz {value} direkt darüber ist frei – angeschweißt wird immer an den Planeten direkt darüber.', ['{value}' => $target->getPosition() - 1]),
            !$top->getOwner()->isSameAs($player) => t('{name} [{coords}] direkt darüber gehört {player}.', ['{name}' => $top->getName(), '{coords}' => $top->getCoordinates(), '{player}' => $top->getOwner()->getUsername()]),
            $target->isIntegrating($now) => t('{name} wurde invadiert und geht erst am {value} in deinen Besitz über.', ['{name}' => $target->getName(), '{value}' => new When($target->getIntegratedAt())]),
            $top->isIntegrating($now) => t('{name} wurde invadiert und geht erst am {value} in deinen Besitz über.', ['{name}' => $top->getName(), '{value}' => new When($top->getIntegratedAt())]),
            0 === $capacity => 'Für eine Fusion wird Fusionstechnik benötigt.',
            $top->getFusionSize() + $target->getFusionSize() > $capacity => t('Deine Fusionstechnik (Stufe {capacity}) erlaubt Fusionen aus höchstens {capacity} Planeten – zusammen wären es {fusionSize}.', ['{capacity}' => $capacity, '{fusionSize}' => $top->getFusionSize() + $target->getFusionSize()]),
            default => null,
        };

        return ['top' => null === $reason ? $top : null, 'reason' => $reason];
    }

    /**
     * Running work that a fusion of these planets would cancel (with refund).
     *
     * @param list<Planet> $planets top planet first
     *
     * @return list<TranslatableInterface>
     */
    public function pendingWork(array $planets): array
    {
        $work = [];
        foreach ($planets as $i => $planet) {
            foreach ($planet->getBuildingQueue() as $order) {
                $work[] = t('Bau von {label} Stufe {targetLevel} auf {name}', ['{label}' => t($order->getBuildingType()->label()), '{targetLevel}' => $order->getTargetLevel(), '{name}' => $planet->getName()]);
            }
            if ($i > 0) {
                foreach ($planet->getShipyardOrders()->toArray() as $order) {
                    $work[] = t('{remaining} × {label} in der Werft von {name}', ['{remaining}' => $order->getRemaining(), '{label}' => t($order->getShipType()->label()), '{name}' => $planet->getName()]);
                }
            }
        }
        $player = $planets[0]->getOwner();
        if ($player->isResearching()) {
            $work[] = t('Forschung an {label} Stufe {researchTargetLevel}', ['{label}' => t($player->getResearchType()->label()), '{researchTargetLevel}' => $player->getResearchTargetLevel()]);
        }

        return $work;
    }

    /**
     * Fusion by fusionators that just arrived at the planet to weld: it joins the own
     * planet directly above. One fusionator is used up, the others fly back.
     * The caller (fleet processing) already holds the fleet event lock.
     *
     * @return int number of fusionators used up
     */
    public function fuseOnArrival(Planet $target, int $fusionators): int
    {
        if ($fusionators < 1) {
            throw new GameException(t('Zum Fusionieren wird ein Fusionator benötigt.'));
        }
        $this->planets->transaction($target, function (Planet $target, \DateTimeImmutable $now): void {
            $this->weld($target, $now);
        });

        return 1;
    }

    /** Welds $target onto the planet above; the crew of the fusionator joins the population. */
    private function weld(Planet $target, \DateTimeImmutable $now): void
    {
        ['top' => $top, 'reason' => $reason] = $this->plan($target);
        if (null === $top) {
            throw new GameException($reason ?? 'Dieser Planet kann nicht angeschweißt werden.');
        }
        $chain = [$target];
        $used = 1;

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

        // Fleets at, from or to a welded slot move to the top planet of the fusion.
        $welded = [];
        foreach ($chain as $planet) {
            foreach (range($planet->getPosition(), $planet->getLastPosition()) as $position) {
                $welded[] = $position;
            }
        }
        foreach ($this->fleets->findTouchingSlots($top->getGalaxy(), $top->getSystem(), $welded) as $fleet) {
            $fleet->followFusion($top->getGalaxy(), $top->getSystem(), $welded, $top->getPosition());
        }
        foreach ($chain as $planet) {
            $top->getOwner()->getPlanets()->removeElement($planet);
            $this->em->remove($planet);
        }

        $this->inbox->send($top->getOwner(), t('Fusion von {name} [{coords}]', ['{name}' => $top->getName(), '{coords}' => $top->getCoordinates()]), new Lines([
            t('{name} wurde an {name2} angeschweißt. Die Fusion umfasst jetzt {fusionSize} Planeten (Plätze {position}–{lastPosition}). Dabei wurde {used} Fusionator verbraucht.', ['{name}' => $chain[0]->getName(), '{name2}' => $top->getName(), '{fusionSize}' => $top->getFusionSize(), '{position}' => $top->getPosition(), '{lastPosition}' => $top->getLastPosition(), '{used}' => $used]),
            ...([] === $cancelled ? [] : ['', t('Abgebrochen (Rohstoffe erstattet):'), ...array_map(static fn (TranslatableInterface $w): TranslatableInterface => new Lines(['  ', $w], ''), $cancelled)]),
        ]));
    }

    public function split(Planet $top): void
    {
        $this->withFleetLock(fn () => $this->planets->transaction($top, function (Planet $top, \DateTimeImmutable $now): void {
            if (!$top->isFused()) {
                throw new GameException(t('Dieser Planet ist keine Fusion.'));
            }

            $cost = Fusion::splitCost($top);
            if (!$top->getResources()->covers($cost)) {
                throw new GameException(t('Zum Sprengen werden {hydrogen} Wasserstoff benötigt.', ['{hydrogen}' => new Num($cost->hydrogen)]));
            }
            $top->setResources($top->getResources()->subtract($cost));

            // Building levels are redistributed, so a running construction can't continue.
            $this->cancelConstruction($top);

            foreach (Fusion::split($top, $now) as $planet) {
                $this->em->persist($planet);
            }
        }));
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
