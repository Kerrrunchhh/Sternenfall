<?php

namespace App\Game;

/**
 * Shape of the universe: galaxies, solar systems and planet slots.
 */
final class Universe
{
    public const int GALAXIES = 3;
    public const int SYSTEMS_PER_GALAXY = 200;
    public const int MIN_PLANETS_PER_SYSTEM = 30;
    public const int MAX_PLANETS_PER_SYSTEM = 50;

    /** Number of planet slots in a system: fixed per system, between 30 and 50. */
    public static function planetsInSystem(int $galaxy, int $system): int
    {
        $spread = self::MAX_PLANETS_PER_SYSTEM - self::MIN_PLANETS_PER_SYSTEM + 1;

        return self::MIN_PLANETS_PER_SYSTEM + crc32("system:$galaxy:$system") % $spread;
    }
}
