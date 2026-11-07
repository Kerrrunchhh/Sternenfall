<?php

namespace App\Game;

use App\Entity\User;

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

    /** One-way flight time in seconds. */
    public function flightSeconds(int $distance, int $speed): int
    {
        return max(5, (int) round(3600 * $distance / max(1, $speed) / $this->economy->speed()));
    }

    /**
     * Hydrogen for the round trip (or one way only).
     *
     * @param array<string, int> $ships
     */
    public function fuel(array $ships, int $distance, bool $oneWay = false): float
    {
        $base = 0;
        foreach ($this->each($ships) as [$type, $count]) {
            $base += $type->spec()->fuel * $count;
        }

        return ceil(($oneWay ? 1 : 2) * $base * (1 + $distance / 10000));
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

    /** @param array<string, int> $ships */
    public function cargoCapacity(array $ships): int
    {
        $capacity = 0;
        foreach ($this->each($ships) as [$type, $count]) {
            $capacity += $type->spec()->cargo * $count;
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
