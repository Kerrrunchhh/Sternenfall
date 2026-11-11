<?php

namespace App\Game;

use App\Entity\User;

use function Symfony\Component\Translation\t;

/**
 * Flight calculations: distance, duration, fuel, crew supplies and cargo space.
 *
 * Fleets are described as arrays of ship type value => number of ships.
 */
final readonly class Navigation
{
    public function __construct(private Economy $economy)
    {
    }

    public function distance(int $galaxy1, int $system1, int $position1, int $galaxy2, int $system2, int $position2): int
    {
        return match (true) {
            $galaxy1 !== $galaxy2 => 100000 * abs($galaxy1 - $galaxy2),
            $system1 !== $system2 => 20000 + 500 * abs($system1 - $system2),
            $position1 !== $position2 => 1000 + 100 * abs($position1 - $position2),
            default => 5,
        };
    }

    /** @param array<string, int> $ships */
    public function slowestSpeed(array $ships, User $player): int
    {
        $speeds = [];
        foreach ($this->each($ships) as [$type]) {
            $speeds[] = $this->economy->shipSpeed($type, $player);
        }

        return [] === $speeds ? 0 : min($speeds);
    }

    public const float MIN_SPEED_PERCENT = 0.1;
    public const float MAX_SPEED_PERCENT = 100.0;

    /** One-way flight time in seconds; fleets may fly at a fraction of their speed (in percent). */
    public function flightSeconds(int $distance, int $speed, float $percent = 100.0): int
    {
        $effective = max(1, $speed) * self::clampPercent($percent) / 100;

        return max(5, (int) round(3600 * $distance / $effective / $this->economy->speed()));
    }

    public static function clampPercent(float $percent): float
    {
        return max(self::MIN_SPEED_PERCENT, min(self::MAX_SPEED_PERCENT, $percent));
    }

    /** Parses "37,5" or "37.5"; throws for anything outside 0.1–100. */
    public static function parsePercent(string $input): float
    {
        $input = trim(str_replace(',', '.', $input));
        if ('' === $input) {
            return self::MAX_SPEED_PERCENT;
        }
        if (!is_numeric($input) || (float) $input < self::MIN_SPEED_PERCENT || (float) $input > self::MAX_SPEED_PERCENT) {
            throw new GameException(t('Die Geschwindigkeit muss zwischen 0,1 und 100 % liegen.'));
        }

        return round((float) $input, 2);
    }

    /**
     * Hydrogen for the round trip (or one way only). Slower flights burn less: the hydrogen
     * shrinks in proportion to the speed (50 % speed = half the hydrogen), while food and
     * water grow with the longer flight time (see supplies()).
     *
     * @param array<string, int> $ships
     */
    public function fuel(array $ships, int $distance, bool $oneWay = false, float $percent = 100.0): float
    {
        return ceil(($oneWay ? 1 : 2) * $this->fuelBase($ships) * (1 + $distance / 10000) * self::clampPercent($percent) / 100);
    }

    /**
     * Hydrogen per unit of the distance factor at full speed (sum over the ships), for the flight preview.
     *
     * @param array<string, int> $ships
     */
    public function fuelBase(array $ships): float
    {
        $base = 0;
        foreach ($this->each($ships) as [$type, $count]) {
            $base += $type->spec()->fuel * $count;
        }

        return $base;
    }

    /**
     * Food and water the crews eat during the round trip (or one way only).
     *
     * @param array<string, int> $ships
     */
    public function supplies(array $ships, int $oneWaySeconds, bool $oneWay = false): Resources
    {
        return $this->economy->consumptionPerHour($this->crew($ships))->multiply(($oneWay ? 1 : 2) * $oneWaySeconds / 3600)->ceil();
    }

    /** @param array<string, int> $ships */
    public function crew(array $ships): int
    {
        $crew = 0;
        foreach ($this->each($ships) as [$type, $count]) {
            $crew += $type->spec()->crew * $count;
        }

        return $crew;
    }

    /**
     * Cargo space of the trade ships among them (no other ship carries cargo).
     *
     * @param array<string, int> $ships
     */
    public function cargoCapacity(array $ships): int
    {
        $capacity = 0;
        foreach ($this->each($ships) as [$type, $count]) {
            $capacity += $type->cargo() * $count;
        }

        return $capacity;
    }

    /**
     * @param array<string, int> $ships
     *
     * @return iterable<array{ShipType, int}>
     */
    private function each(array $ships): iterable
    {
        foreach ($ships as $type => $count) {
            if ($count > 0) {
                yield [ShipType::from($type), $count];
            }
        }
    }
}
