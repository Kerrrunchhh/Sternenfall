<?php

namespace App\Tests\Game;

use App\Entity\Fleet;
use App\Entity\User;
use App\Game\FleetMission;
use App\Game\Radar;
use App\Game\Resources;
use PHPUnit\Framework\TestCase;

/** "Stationieren" took over "Springen": one mission for moving ships to where they stay. */
class StationTest extends TestCase
{
    public function testStationingIsTheOnlyMissionForMovingShipsSomewhereToStay(): void
    {
        self::assertContains(FleetMission::Station, FleetMission::selectable());
        self::assertNotContains(FleetMission::Jump, FleetMission::selectable(), 'part of stationing now');
        self::assertNotContains(FleetMission::Supply, FleetMission::selectable(), 'a transport fills a trade post');
        self::assertNotContains(FleetMission::Colonize, FleetMission::selectable(), 'colony ships start from the hangar in the universe');
    }

    public function testStationingWithoutCargoIsInvisible(): void
    {
        foreach ([['freighter' => 3], ['leviathan' => 2]] as $ships) {
            $fleet = new Fleet(new User('verleger'), 'Verlegung', 1, 1, 1, $ships);
            $fleet->depart(FleetMission::Station, 1, 5, 7, new \DateTimeImmutable('2026-01-01 12:00'), 600);

            self::assertFalse(Radar::emits($fleet), implode(',', array_keys($ships)));
        }
    }

    public function testStationingWithCargoGivesItselfAway(): void
    {
        $fleet = new Fleet(new User('verleger'), 'Konvoi', 1, 1, 1, ['freighter' => 1, 'pfeil' => 5], new Resources(iron: 1000));
        $fleet->depart(FleetMission::Station, 1, 5, 7, new \DateTimeImmutable('2026-01-01 12:00'), 600);

        self::assertTrue(Radar::emits($fleet));
    }

    public function testEveryMissionHasAKindOfFlightForTheOverview(): void
    {
        foreach (FleetMission::cases() as $mission) {
            self::assertArrayHasKey($mission->kind(), FleetMission::KINDS, $mission->value);
        }
        self::assertSame('war', FleetMission::Raid->kind());
        self::assertSame('trade', FleetMission::Pickup->kind());
        self::assertSame('move', FleetMission::Station->kind());
    }
}
