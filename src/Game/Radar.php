<?php

namespace App\Game;

use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\User;
use App\Repository\FleetRepository;

/**
 * What a player can see of other fleets. Waiting fleets are invisible. Moving
 * fleets become visible when they attack or carry resources, and only to players
 * whose radar covers the system they are heading to: radar 1 covers the own
 * systems, every 5 further levels one more system to the left and right.
 */
final readonly class Radar
{
    public function __construct(private FleetRepository $fleets)
    {
    }

    /** Systems to the left and right that are covered, or null without radar. */
    public static function range(int $level): ?int
    {
        return $level < 1 ? null : intdiv($level, 5);
    }

    public function covers(User $player, int $galaxy, int $system): bool
    {
        return [] !== $this->coveringPlanets($player, $galaxy, $system);
    }

    /** @return list<Planet> the player's planets whose radar reaches the system */
    public function coveringPlanets(User $player, int $galaxy, int $system): array
    {
        $range = self::range($player->getResearchLevel(ResearchType::Radar));
        if (null === $range) {
            return [];
        }

        return array_values(array_filter(
            $player->getPlanets()->toArray(),
            static fn (Planet $planet): bool => $planet->getGalaxy() === $galaxy && abs($planet->getSystem() - $system) <= $range,
        ));
    }

    /** Fleets that give themselves away: attackers and resource transports. */
    public static function emits(Fleet $fleet): bool
    {
        if (!$fleet->isMoving() || FleetMission::Gather === $fleet->getMission()) {
            return false;
        }

        return (FleetMission::Attack === $fleet->getMission() && !$fleet->isReturning()) || $fleet->getCargo()->total() >= 1;
    }

    /** Coordinates of the next place the fleet will reach. @return array{int, int, int} */
    public static function nextStop(Fleet $fleet): array
    {
        return $fleet->isReturning()
            ? [$fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition()]
            : [$fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition()];
    }

    public function sees(User $player, Fleet $fleet): bool
    {
        if ($fleet->getOwner()->isSameAs($player)) {
            return true;
        }
        if (!self::emits($fleet)) {
            return false;
        }
        [$galaxy, $system] = self::nextStop($fleet);

        return $this->covers($player, $galaxy, $system);
    }

    /** @return list<Fleet> foreign fleets on the player's radar */
    public function visibleFleets(User $player): array
    {
        return array_values(array_filter(
            $this->fleets->findMovingOfOthers($player),
            fn (Fleet $fleet): bool => $this->sees($player, $fleet),
        ));
    }
}
