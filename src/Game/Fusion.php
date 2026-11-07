<?php

namespace App\Game;

use App\Entity\Planet;

/**
 * Pure fusion rules (no persistence):
 * - welding adds up the building levels, resources, population and ships of all planets;
 * - blowing a fusion apart restores the welded planets and spreads building levels,
 *   resources and population as evenly as possible. Ships stay on the topmost planet.
 */
final class Fusion
{
    /** Hydrogen needed per welded planet to blow a fusion apart. */
    public const int HYDROGEN_PER_PLANET = 100_000;

    /**
     * Welds the given planets (consecutive, directly below $top) into $top.
     *
     * @param list<Planet> $absorbed
     */
    public static function merge(Planet $top, array $absorbed): void
    {
        foreach ($absorbed as $planet) {
            foreach (BuildingType::cases() as $type) {
                $top->setBuildingLevel($type, $top->getBuildingLevel($type) + $planet->getBuildingLevel($type));
            }
            $top->setResources($top->getResources()->add($planet->getResources()));
            $top->setPopulation($top->getPopulation() + $planet->getPopulation());
            foreach (ShipType::cases() as $ship) {
                if ($planet->getShipCount($ship) > 0) {
                    $top->addShips($ship, $planet->getShipCount($ship));
                }
            }
            $top->absorb($planet);
        }
    }

    public static function splitCost(Planet $planet): Resources
    {
        return new Resources(hydrogen: self::HYDROGEN_PER_PLANET * ($planet->getFusionSize() - 1));
    }

    /**
     * Blows a fusion apart. Returns the restored planets (not yet persisted); $top keeps its slot and the ships.
     *
     * @return list<Planet>
     */
    public static function split(Planet $top, \DateTimeImmutable $now): array
    {
        $restored = [];
        foreach ($top->getFusedPlanets() as $part) {
            $restored[] = new Planet($top->getOwner(), $part['name'], $top->getGalaxy(), $top->getSystem(), $part['position'], $now);
        }
        $all = [$top, ...$restored];
        $count = \count($all);

        foreach (BuildingType::cases() as $type) {
            foreach (self::distribute($top->getBuildingLevel($type), $count) as $i => $level) {
                $all[$i]->setBuildingLevel($type, $level);
            }
        }

        $share = $top->getResources()->multiply(1 / $count);
        $population = $top->getPopulation() / $count;
        foreach ($all as $planet) {
            $planet->setResources($share);
            $planet->setPopulation($population);
        }

        $top->resetFusion();

        return $restored;
    }

    /**
     * Spreads $total as evenly as possible over $parts; earlier parts get the remainder.
     *
     * @return list<int>
     */
    public static function distribute(int $total, int $parts): array
    {
        $base = intdiv($total, $parts);
        $remainder = $total % $parts;

        return array_map(static fn (int $i): int => $base + ($i < $remainder ? 1 : 0), range(0, $parts - 1));
    }
}
