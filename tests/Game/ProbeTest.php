<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\Economy;
use App\Game\FleetMission;
use App\Game\ShipType;
use PHPUnit\Framework\TestCase;

class ProbeTest extends TestCase
{
    public function testOnlyTheScoutIsAProbe(): void
    {
        foreach (ShipType::cases() as $type) {
            self::assertSame(ShipType::Scout === $type, $type->isProbe(), $type->label());
        }
    }

    public function testScoutIsTheCheapestShip(): void
    {
        $economy = new Economy(1.0);
        $scout = ShipType::Scout->spec()->cost->total();

        self::assertLessThanOrEqual(150, $scout);
        foreach (ShipType::cases() as $type) {
            if (ShipType::Scout !== $type) {
                self::assertLessThan($type->spec()->cost->total(), $scout, $type->label());
            }
        }
        self::assertLessThanOrEqual(10, $economy->shipCredits(ShipType::Scout, new User('wächter')));
    }

    public function testSpyingIsNoFleetMission(): void
    {
        self::assertNotContains(FleetMission::Spy, FleetMission::selectable());
    }
}
