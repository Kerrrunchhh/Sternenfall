<?php

namespace App\Tests\Game;

use App\Entity\Fleet;
use App\Entity\User;
use App\Game\FleetMission;
use PHPUnit\Framework\TestCase;

/** A fleet allowed to stay somewhere loses that permission when it moves on. */
final class FleetStayTest extends TestCase
{
    public function testThePermissionDoesNotTravelWithTheFleet(): void
    {
        $now = new \DateTimeImmutable('2026-11-12 10:00:00');
        $fleet = new Fleet(new User('admiral', $now), 'Wache', 1, 1, 1, ['pfeil' => 1]);

        $fleet->allowStay(true, $now);
        self::assertTrue($fleet->isStayAllowed());
        self::assertEquals($now, $fleet->getParkedAt(), 'last checked now');

        $fleet->depart(FleetMission::Station, 2, 2, 2, $now, 60);
        self::assertFalse($fleet->isStayAllowed(), 'at the next place it is checked anew');

        $fleet->allowStay(false, $now->modify('+1 hour'));
        self::assertFalse($fleet->isStayAllowed());
        self::assertEquals($now->modify('+1 hour'), $fleet->getParkedAt(), 'the 24 hours run from here');
    }
}
