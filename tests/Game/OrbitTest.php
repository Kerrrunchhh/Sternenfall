<?php

namespace App\Tests\Game;

use App\Game\Orbit;
use App\Game\SunType;
use App\Game\Universe;
use PHPUnit\Framework\TestCase;

/** Planets circle their sun: distances within a system change all the time. */
class OrbitTest extends TestCase
{
    public function testInnerPlanetsCircleFasterThanOuterOnes(): void
    {
        $slots = Universe::planetsInSystem(1, 1);

        self::assertEqualsWithDelta(86400, Orbit::periodSeconds(1, 1, 1), 0.001, 'slot 1: once a day');
        self::assertEqualsWithDelta(14 * 86400, Orbit::periodSeconds(1, 1, $slots), 0.001, 'outermost slot: two weeks');
        foreach (range(2, $slots) as $position) {
            self::assertGreaterThan(Orbit::periodSeconds(1, 1, $position - 1), Orbit::periodSeconds(1, 1, $position));
        }
    }

    public function testAPlanetIsBackWhereItWasAfterOneOrbit(): void
    {
        $at = new \DateTimeImmutable('2026-11-14 12:00:00 UTC');
        $period = (int) round(Orbit::periodSeconds(3, 42, 1));

        self::assertEqualsWithDelta(Orbit::angle(3, 42, 1, $at), Orbit::angle(3, 42, 1, $at->modify("+$period seconds")), 1e-6);
        self::assertNotEqualsWithDelta(Orbit::angle(3, 42, 1, $at), Orbit::angle(3, 42, 1, $at->modify('+6 hours')), 0.1, 'a quarter of the way on');
    }

    public function testTheFlightDistanceChangesBetweenTheClosestAndTheFarthestPosition(): void
    {
        $start = new \DateTimeImmutable('2026-11-14 00:00:00 UTC');
        $distances = [];
        for ($hour = 0; $hour < 24 * 30; ++$hour) {
            $distances[] = Orbit::distance(1, 1, 5, 6, $start->modify("+$hour hours"));
        }

        // Neighbours: the radii are 700 and 800 – side by side 100 apart, on opposite sides 1500.
        self::assertGreaterThanOrEqual(Orbit::TRAVEL_BASE + 100, min($distances));
        self::assertLessThanOrEqual(Orbit::TRAVEL_BASE + 1500, max($distances));
        self::assertGreaterThan(2.0, max($distances) / min($distances), 'the stars being right makes a real difference');
        self::assertSame(Orbit::distance(1, 1, 5, 6, $start), Orbit::distance(1, 1, 6, 5, $start), 'the same both ways');
    }

    public function testFlightsWithinASystemStayShorterThanToTheNextSystem(): void
    {
        $at = new \DateTimeImmutable('2026-11-14 00:00:00 UTC');
        $farthest = Orbit::TRAVEL_BASE + Orbit::radius(Universe::MAX_PLANETS_PER_SYSTEM) + Orbit::radius(Universe::MAX_PLANETS_PER_SYSTEM - 1);

        self::assertLessThan(20500, $farthest);
        self::assertLessThanOrEqual($farthest, Orbit::distance(1, 1, 1, 10, $at));
    }

    public function testGalaxiesCircleTheCentreInnerOnesFaster(): void
    {
        self::assertEqualsWithDelta(90 * 86400, Orbit::galaxyPeriodSeconds(1), 1);
        self::assertEqualsWithDelta(365 * 86400, Orbit::galaxyPeriodSeconds(Universe::GALAXIES), 1);
        self::assertLessThan(Orbit::galaxyPeriodSeconds(13), Orbit::galaxyPeriodSeconds(12));

        $at = new \DateTimeImmutable('2026-11-19 00:00:00 UTC');
        self::assertEqualsWithDelta(Orbit::galaxyAngle(7, $at), Orbit::galaxyAngle(7, $at->modify('+'.(int) Orbit::galaxyPeriodSeconds(7).' seconds')), 1e-6);
    }

    public function testTheWayBetweenGalaxiesChangesButStaysAsLongAsBeforeOnAverage(): void
    {
        $start = new \DateTimeImmutable('2026-11-19 00:00:00 UTC');
        self::assertSame(0, Orbit::galaxyDistance(4, 4, $start));
        self::assertSame(Orbit::galaxyDistance(4, 9, $start), Orbit::galaxyDistance(9, 4, $start));

        // Between the closest and the farthest position, and it moves.
        $a = Orbit::galaxyRadius(4);
        $b = Orbit::galaxyRadius(9);
        $seen = [];
        for ($day = 0; $day < 365; $day += 5) {
            $d = Orbit::galaxyDistance(4, 9, $start->modify("+$day days"));
            self::assertGreaterThanOrEqual(Orbit::GALAXY_TRAVEL_BASE + $b - $a - 1, $d);
            self::assertLessThanOrEqual(Orbit::GALAXY_TRAVEL_BASE + $a + $b + 1, $d);
            $seen[] = $d;
        }
        self::assertGreaterThan(100000, max($seen) - min($seen), 'the galaxies move against each other');

        // Over all pairs and a year: about as far as the old 100,000 per galaxy (866,667 on average).
        $sum = 0;
        $count = 0;
        for ($day = 0; $day < 365; $day += 30) {
            $at = $start->modify("+$day days");
            for ($g = 1; $g <= Universe::GALAXIES; ++$g) {
                for ($h = 1; $h <= Universe::GALAXIES; ++$h) {
                    if ($g !== $h) {
                        $sum += Orbit::galaxyDistance($g, $h, $at);
                        ++$count;
                    }
                }
            }
        }
        self::assertEqualsWithDelta(866667, $sum / $count, 100000);
    }

    public function testEverySystemHasAFixedSun(): void
    {
        self::assertSame(SunType::of(4, 123), SunType::of(4, 123));
        $types = [];
        foreach (range(1, 200) as $system) {
            $types[SunType::of(1, $system)->value] = true;
        }
        self::assertCount(\count(SunType::cases()), $types, 'all kinds of suns occur');
    }
}
