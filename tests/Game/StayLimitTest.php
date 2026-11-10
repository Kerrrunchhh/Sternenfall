<?php

namespace App\Tests\Game;

use App\Entity\Fleet;
use App\Entity\User;
use App\Game\FleetMission;
use PHPUnit\Framework\TestCase;

/** Fleets stranded on a foreign planet without fuel lose 10 % of their ships a day. */
final class StayLimitTest extends TestCase
{
    public function testStrandedFleetLosesTenPercentADayAndIsGoneAfterTenDays(): void
    {
        $since = new \DateTimeImmutable('2026-01-02 12:00');
        $fleet = new Fleet(new User('pilot'), 'Gestrandet', 1, 1, 1, ['pfeil' => 100, 'freighter' => 3]);

        self::assertSame(0, $fleet->strand($since, $since->modify('+5 hours')), 'nothing lost on the first day');
        self::assertSame(['pfeil' => 100, 'freighter' => 3], $fleet->getShips());

        $fleet->strand($since, $since->modify('+1 day'));
        self::assertSame(['pfeil' => 90, 'freighter' => 3], $fleet->getShips(), '10 % of 3 rounds up to keep them a while');

        $fleet->strand($since, $since->modify('+5 days'));
        self::assertSame(['pfeil' => 50, 'freighter' => 2], $fleet->getShips());

        $fleet->strand($since, $since->modify('+10 days'));
        self::assertSame([], $fleet->getShips(), 'after 10 days the fleet is gone');
    }

    public function testDepartingEndsTheStrandingAndLandingStartsTheClock(): void
    {
        $now = new \DateTimeImmutable('2026-01-02 12:00');
        $fleet = new Fleet(new User('pilot'), 'Flotte', 1, 1, 1, ['pfeil' => 10]);
        $fleet->strand($now, $now);

        $fleet->depart(FleetMission::Station, 1, 1, 2, $now, 60);
        self::assertNull($fleet->getStrandedSince());
        self::assertNull($fleet->getParkedAt());

        $fleet->settleAtTarget();
        self::assertEquals($now->modify('+60 seconds'), $fleet->getParkedAt(), 'waiting since its arrival');
    }
}
