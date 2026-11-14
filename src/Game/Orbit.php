<?php

namespace App\Game;

/**
 * The planet slots of a solar system circle its sun: slot 1 once a day, the outermost slot
 * of the system in OUTER_PERIOD_DAYS, the ones between slower the further out (like Kepler).
 * Where on its orbit a slot starts is fixed per slot. Flights within a system therefore get
 * shorter and longer all the time: TRAVEL_BASE plus the real distance between the two slots
 * at the moment of departure – "when the stars are right".
 *
 * public/js/game.js (orbit) computes the same for the system view and the flight preview.
 */
final class Orbit
{
    /** Moment all orbits count from: 01.01.2026 00:00 UTC. */
    public const int EPOCH = 1767225600;
    public const float INNER_PERIOD_DAYS = 1;
    public const float OUTER_PERIOD_DAYS = 14;
    /** Orbit radius: RADIUS_BASE + RADIUS_STEP per slot (in distance units). */
    public const int RADIUS_BASE = 200;
    public const int RADIUS_STEP = 100;
    /** Leaving and approaching a planet, on top of the distance between the two. */
    public const int TRAVEL_BASE = 600;

    public static function radius(int $position): int
    {
        return self::RADIUS_BASE + self::RADIUS_STEP * $position;
    }

    /** Seconds for one orbit of the slot. */
    public static function periodSeconds(int $galaxy, int $system, int $position): float
    {
        $slots = Universe::planetsInSystem($galaxy, $system);
        $share = $slots > 1 ? max(0, min(1, ($position - 1) / ($slots - 1))) : 0.0;

        return 86400 * (self::INNER_PERIOD_DAYS + (self::OUTER_PERIOD_DAYS - self::INNER_PERIOD_DAYS) * $share ** 1.5);
    }

    /** Angle of the slot on its orbit (radians) at the moment. */
    public static function angle(int $galaxy, int $system, int $position, \DateTimeInterface $at): float
    {
        $phase = crc32("orbit:$galaxy:$system:$position") / 4294967296 * 2 * M_PI;
        $turns = ($at->getTimestamp() - self::EPOCH) / self::periodSeconds($galaxy, $system, $position);

        return fmod($phase + 2 * M_PI * $turns, 2 * M_PI);
    }

    /** Flight distance between two slots of the same system at the moment. */
    public static function distance(int $galaxy, int $system, int $from, int $to, \DateTimeInterface $at): int
    {
        $a = self::radius($from);
        $b = self::radius($to);
        $between = self::angle($galaxy, $system, $from, $at) - self::angle($galaxy, $system, $to, $at);

        return self::TRAVEL_BASE + (int) round(sqrt(max(0.0, $a * $a + $b * $b - 2 * $a * $b * cos($between))));
    }
}
