<?php

namespace App\Game;

use App\Entity\Planet;
use App\Entity\User;

/**
 * Points: every building has a points rate that is multiplied by its level
 * (mines 1, electrolysis 2, shipyard/lab/shield generator 3, command center 4 …).
 * A planet's points are the sum over its buildings, a player's the sum of his planets.
 * Being linear, welding planets neither gains nor loses points.
 */
final class Scoring
{
    public static function points(User $player): int
    {
        return array_sum(array_map(self::planetPoints(...), $player->getPlanets()->toArray()));
    }

    /** Research points: the levels of all researches added up. */
    public static function researchPoints(User $player): int
    {
        return array_sum(array_map(static fn (ResearchType $type): int => $player->getResearchLevel($type), ResearchType::cases()));
    }

    public static function planetPoints(Planet $planet): int
    {
        $points = 0;
        foreach (BuildingType::cases() as $type) {
            $points += $planet->getBuildingLevel($type) * $type->pointsPerLevel();
        }

        return $points;
    }
}
