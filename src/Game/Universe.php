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

    /** The kind of world in a slot: fixed per slot, each type equally likely. */
    public static function planetType(int $galaxy, int $system, int $position): PlanetType
    {
        $types = PlanetType::cases();

        return $types[crc32("planet:$galaxy:$system:$position") % \count($types)];
    }

    /** Number of planet slots in a system: fixed per system, between 30 and 50. */
    public static function isValidSlot(int $galaxy, int $system, int $position): bool
    {
        return $galaxy >= 1 && $galaxy <= self::GALAXIES
            && $system >= 1 && $system <= self::SYSTEMS_PER_GALAXY
            && $position >= 1 && $position <= self::planetsInSystem($galaxy, $system);
    }

    public static function planetsInSystem(int $galaxy, int $system): int
    {
        $spread = self::MAX_PLANETS_PER_SYSTEM - self::MIN_PLANETS_PER_SYSTEM + 1;

        return self::MIN_PLANETS_PER_SYSTEM + crc32("system:$galaxy:$system") % $spread;
    }
}
