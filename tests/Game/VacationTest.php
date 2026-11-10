<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\GameException;
use App\Game\ResearchType;
use App\Game\VacationService;
use PHPUnit\Framework\TestCase;

/** On vacation everything rests; afterwards all clocks go on where they stopped. */
final class VacationTest extends TestCase
{
    public function testClocksStandStillAndAreShiftedAfterwards(): void
    {
        $start = new \DateTimeImmutable('2026-01-01 12:00');
        $player = new User('urlauber', $start);
        $planet = new Planet($player, 'Heim', 1, 1, 1, $start);
        $player->startResearch(ResearchType::Espionage, 1, $start->modify('+2 hours'), $planet);
        $planet->setAbandonAt($start->modify('+10 hours'));

        $player->startVacation($start, $start->modify('+3 days'));
        self::assertTrue($player->isOnVacation($start->modify('+1 day')));
        self::assertEquals($start, $player->clockAt($start->modify('+2 days')), 'production and queues stop at the start');

        $seconds = $player->endVacation($start->modify('+3 days'));
        $planet->shiftClock($seconds);

        self::assertSame(3 * 86400, $seconds);
        self::assertFalse($player->isOnVacation($start->modify('+3 days')));
        self::assertEquals($start->modify('+3 days +2 hours'), $player->getResearchFinishesAt(), 'research goes on where it stopped');
        self::assertEquals($start->modify('+3 days'), $planet->getResourcesUpdatedAt());
        self::assertEquals($start->modify('+3 days +10 hours'), $planet->getAbandonAt());
    }

    public function testDaysMayHaveDecimals(): void
    {
        self::assertSame(2.5, VacationService::parseDays('2,5'));
        self::assertSame(1.25, VacationService::parseDays(' 1.25 '));
        $this->expectException(GameException::class);
        VacationService::parseDays('zwei');
    }
}
