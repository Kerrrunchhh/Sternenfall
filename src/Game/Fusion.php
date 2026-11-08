<?php

namespace App\Game;

use App\Entity\Planet;

/**
 * Pure fusion rules (no persistence):
 * - welding adds up the building levels, resources, population and ships of all planets;
 * - blowing a fusion apart restores the welded planets and spreads building levels,
 *   resources and population as evenly as possible. Ships stay on the topmost planet,
 *   all defenses are destroyed;
 * - an attack through the shield or an invasion breaks off the bottom planet only:
 *   it takes its share (1 / size of the fusion) of resources, population and buildings.
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
            // A welded home planet passes its protection on to the fusion.
            if ($planet->isHomeworld()) {
                $top->markHomeworld();
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

        // Blowing a fusion apart destroys every defense installation.
        foreach (ShipType::cases() as $ship) {
            if ($ship->isStationary() && $top->getShipCount($ship) > 0) {
                $top->addShips($ship, -$top->getShipCount($ship));
            }
        }

        $top->resetFusion();

        return $restored;
    }

    /**
     * Breaks the bottom planet off a fusion (not yet persisted). It takes its share of
     * resources, population and building levels; ships and defenses stay with the fusion.
     */
    public static function breakOff(Planet $top, \DateTimeImmutable $now): Planet
    {
        $share = 1 / $top->getFusionSize();
        $bottom = $top->detachBottom();
        $planet = new Planet($top->getOwner(), $bottom['name'], $top->getGalaxy(), $top->getSystem(), $bottom['position'], $now);

        $resources = $top->getResources()->multiply($share)->floor();
        $planet->setResources($resources);
        $top->setResources($top->getResources()->subtract($resources));

        $population = floor($top->getPopulation() * $share);
        $planet->setPopulation($population);
        $top->setPopulation($top->getPopulation() - $population);

        foreach (BuildingType::cases() as $type) {
            $levels = (int) floor($top->getBuildingLevel($type) * $share);
            $planet->setBuildingLevel($type, $levels);
            $top->setBuildingLevel($type, $top->getBuildingLevel($type) - $levels);
        }

        return $planet;
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
