<?php

namespace App\Tests\Game;

use App\Entity\Fleet;
use App\Entity\User;
use App\Game\FleetMission;
use App\Game\Radar;
use App\Game\Resources;
use PHPUnit\Framework\TestCase;

class JumpTest extends TestCase
{
    public function testJumpIsAMissionPlayersCanChoose(): void
    {
        self::assertContains(FleetMission::Jump, FleetMission::selectable());
        self::assertSame('Springen', FleetMission::Jump->label());
    }

    public function testJumpWithoutCargoIsInvisible(): void
    {
        foreach ([['freighter' => 3], ['leviathan' => 2]] as $ships) {
            $fleet = new Fleet(new User('springer'), 'Sprung', 1, 1, 1, $ships);
            $fleet->depart(FleetMission::Jump, 1, 5, 7, new \DateTimeImmutable('2026-01-01 12:00'), 600);

            self::assertFalse(Radar::emits($fleet), implode(',', array_keys($ships)));
        }
    }

    public function testJumpWithCargoGivesItselfAway(): void
    {
        $fleet = new Fleet(new User('springer'), 'Konvoi', 1, 1, 1, ['freighter' => 1, 'pfeil' => 5], new Resources(iron: 1000));
        $fleet->depart(FleetMission::Jump, 1, 5, 7, new \DateTimeImmutable('2026-01-01 12:00'), 600);

        self::assertTrue(Radar::emits($fleet));
    }
}
