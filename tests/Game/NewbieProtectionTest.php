<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\FleetMission;
use App\Game\NewbieProtection;
use PHPUnit\Framework\TestCase;

/** One planet and no fleet control center: nobody may attack, station at or send resources to that player. */
final class NewbieProtectionTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-12 10:00:00');
    }

    public function testProtectedWithOnePlanetAndNoControlCenter(): void
    {
        $newbie = new User('neu', $this->now);
        $home = new Planet($newbie, 'Heim', 1, 1, 1, $this->now);
        self::assertTrue(NewbieProtection::isProtected($newbie));

        $home->setBuildingLevel(BuildingType::FleetControl, 1);
        self::assertFalse(NewbieProtection::isProtected($newbie), 'a fleet control center ends it');

        $other = new User('kolonist', $this->now);
        new Planet($other, 'Heim', 2, 1, 1, $this->now);
        new Planet($other, 'Kolonie', 2, 1, 2, $this->now);
        self::assertFalse(NewbieProtection::isProtected($other), 'a second planet ends it');
    }

    public function testBlocksAttackStationAndResourcesButNotSpying(): void
    {
        $newbie = new User('neu', $this->now);
        $target = new Planet($newbie, 'Heim', 1, 1, 1, $this->now);
        $attacker = new User('alt', $this->now);

        foreach ([FleetMission::Attack, FleetMission::Station, FleetMission::Jump, FleetMission::Transport, FleetMission::TransportStation] as $mission) {
            self::assertNotNull(NewbieProtection::refusal($mission, $attacker, $target), $mission->value);
        }
        self::assertNull(NewbieProtection::refusal(FleetMission::Spy, $attacker, $target), 'spying stays allowed');
        self::assertNull(NewbieProtection::refusal(FleetMission::Transport, $newbie, $target), 'own planet');
        self::assertNull(NewbieProtection::refusal(FleetMission::Attack, $attacker, null), 'no planet there');

        $target->setBuildingLevel(BuildingType::FleetControl, 1);
        self::assertNull(NewbieProtection::refusal(FleetMission::Attack, $attacker, $target));
    }
}
