<?php

namespace App\Tests\Game;

use App\Game\Economy;
use App\Game\FleetMission;
use App\Game\Navigation;
use PHPUnit\Framework\TestCase;

/** Slower flights burn less hydrogen (in proportion to the speed) but need more food and water. */
final class SlowFlightConsumptionTest extends TestCase
{
    public function testHydrogenShrinksWithSpeedProvisionsGrowWithFlightTime(): void
    {
        $navigation = new Navigation(new Economy(1.0));
        $ships = ['freighter' => 10];
        $distance = $navigation->distance(1, 1, 1, 1, 5, 1);

        $full = $navigation->fuel($ships, $distance, false, 100.0);
        $half = $navigation->fuel($ships, $distance, false, 50.0);
        self::assertGreaterThan(0, $full);
        self::assertEqualsWithDelta($full / 2, $half, 1.0, '50 % speed, half the hydrogen');
        self::assertSame($full, $navigation->fuel($ships, $distance), 'full speed by default');

        $fast = $navigation->supplies($ships, $navigation->flightSeconds($distance, 1000, 100.0));
        $slow = $navigation->supplies($ships, $navigation->flightSeconds($distance, 1000, 50.0));
        self::assertGreaterThan($fast->food, $slow->food, 'twice the flight time, more food');
        self::assertGreaterThan($fast->water, $slow->water);
    }

    public function testFleetsThatStayAtTheTargetPayForOneWayOnly(): void
    {
        foreach ([FleetMission::Station, FleetMission::Jump, FleetMission::Trade, FleetMission::Spy] as $mission) {
            self::assertTrue($mission->isOneWay(true), $mission->value);
        }
        self::assertTrue(FleetMission::TransportStation->isOneWay(true), 'a named fleet stays');
        self::assertFalse(FleetMission::TransportStation->isOneWay(false), 'unnamed ships fly back');
        foreach ([FleetMission::Transport, FleetMission::Attack, FleetMission::Colonize, FleetMission::Pickup] as $mission) {
            self::assertFalse($mission->isOneWay(true), $mission->value);
        }

        $navigation = new Navigation(new Economy(1.0));
        $ships = ['freighter' => 10];
        $distance = $navigation->distance(1, 1, 1, 1, 5, 1);
        self::assertEqualsWithDelta($navigation->fuel($ships, $distance) / 2, $navigation->fuel($ships, $distance, true), 1.0);
    }
}
