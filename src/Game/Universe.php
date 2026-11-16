<?php

namespace App\Game;

/**
 * Shape of the universe: galaxies, solar systems and planet slots.
 */
final class Universe
{
    public const int GALAXIES = 25;
    public const int SYSTEMS_PER_GALAXY = 500;
    public const int MIN_PLANETS_PER_SYSTEM = 10;
    public const int MAX_PLANETS_PER_SYSTEM = 50;

    /** The kind of world in a slot: fixed per slot, each type equally likely. */
    public static function planetType(int $galaxy, int $system, int $position): PlanetType
    {
        $types = PlanetType::cases();

        return $types[crc32("planet:$galaxy:$system:$position") % \count($types)];
    }

    public static function isValidSlot(int $galaxy, int $system, int $position): bool
    {
        return $galaxy >= 1 && $galaxy <= self::GALAXIES
            && $system >= 1 && $system <= self::SYSTEMS_PER_GALAXY
            && $position >= 1 && $position <= self::planetsInSystem($galaxy, $system);
    }

    /**
     * Number of planet slots in a system: fixed per system, anything from 10 to 50,
     * small systems more common than large ones (about half have fewer than 20).
     * Mirrored in public/js/game.js (orbit.slots) for the flight preview and the system view – change both together.
     */
    public static function planetsInSystem(int $galaxy, int $system): int
    {
        $spread = self::MAX_PLANETS_PER_SYSTEM - self::MIN_PLANETS_PER_SYSTEM;
        $roll = crc32("slots:$galaxy:$system") % 10001;
        // The trade guild's central systems keep the size they were built with (until 2026-11-16 the
        // largest size only came up for the very top roll): its fusion spans exactly those slots.
        if (TradeGuild::SYSTEM === $system) {
            return self::MIN_PLANETS_PER_SYSTEM + intdiv($spread * $roll ** 2, 10000 * 10000);
        }

        // spread + 1 steps over the whole roll, so 50 slots are about as common as 49.
        return self::MIN_PLANETS_PER_SYSTEM + intdiv(($spread + 1) * $roll ** 2, 10001 * 10001);
    }
}
