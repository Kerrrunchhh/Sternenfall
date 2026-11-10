<?php

namespace App\Game;

use App\Entity\Planet;
use App\Entity\User;

/**
 * Points of a player: how far his planets are built up. Every building level counts with
 * the resources it cost, 1 point per 1.000. A fusion counts like its single planets
 * (levels spread evenly), so welding planets neither gains nor loses points.
 */
final class Scoring
{
    public const int RESOURCES_PER_POINT = 1000;

    public static function points(User $player): int
    {
        return (int) floor(array_sum(array_map(self::planetValue(...), $player->getPlanets()->toArray())) / self::RESOURCES_PER_POINT);
    }

    /** Resources invested in the buildings of a planet. */
    public static function planetValue(Planet $planet): float
    {
        $size = max(1, $planet->getFusionSize());
        $value = 0.0;
        foreach (BuildingType::cases() as $type) {
            $level = $planet->getBuildingLevel($type);
            $base = intdiv($level, $size);
            $extra = $level % $size;
            $value += ($size - $extra) * self::investment($type, $base) + $extra * self::investment($type, $base + 1);
        }

        return $value;
    }

    /** Resources all levels 1..n of a building cost together. */
    public static function investment(BuildingType $type, int $level): float
    {
        if ($level <= 0) {
            return 0.0;
        }
        $factor = $type->costFactor();
        $levels = 1.0 === $factor ? $level : ($factor ** $level - 1) / ($factor - 1);

        return $type->baseCost()->total() * $levels;
    }
}
