<?php

namespace App\Tests\Game;

use App\Game\AwardPeriod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/** The periods of the arrows: whole weeks (from Monday), months and years in German time. */
class AwardPeriodTest extends TestCase
{
    /** @return iterable<string, array{AwardPeriod, string, string, string, string}> period, now (UTC), start and end (German time), name */
    public static function periods(): iterable
    {
        // Sunday 15.11.2026: the last whole week is 02.–09.11.
        yield 'week' => [AwardPeriod::Week, '2026-11-15 12:00 UTC', '2026-11-02 00:00', '2026-11-09 00:00', 'KW 45/2026'];
        // Monday 16.11. 00:30 German time is still Sunday 23:30 UTC: the week 09.–16.11. is over.
        yield 'week just over' => [AwardPeriod::Week, '2026-11-15 23:30 UTC', '2026-11-09 00:00', '2026-11-16 00:00', 'KW 46/2026'];
        yield 'month' => [AwardPeriod::Month, '2026-11-15 12:00 UTC', '2026-10-01 00:00', '2026-11-01 00:00', '10/2026'];
        // The year 2026 is over at midnight German time – 23:00 UTC on New Year's Eve.
        yield 'year' => [AwardPeriod::Year, '2026-12-31 23:30 UTC', '2026-01-01 00:00', '2027-01-01 00:00', '2026'];
        yield 'year not yet over' => [AwardPeriod::Year, '2026-12-31 22:30 UTC', '2025-01-01 00:00', '2026-01-01 00:00', '2025'];
        // Week 53 of 2026 runs into 2027: the ISO year counts.
        yield 'week across the new year' => [AwardPeriod::Week, '2027-01-05 12:00 UTC', '2026-12-28 00:00', '2027-01-04 00:00', 'KW 53/2026'];
    }

    /** @dataProvider periods */
    #[\PHPUnit\Framework\Attributes\DataProvider('periods')]
    public function testTheLastCompletedPeriod(AwardPeriod $period, string $now, string $start, string $end, string $name): void
    {
        [$from, $to] = $period->lastCompleted(new \DateTimeImmutable($now));
        $berlin = new \DateTimeZone('Europe/Berlin');

        self::assertSame($start, $from->setTimezone($berlin)->format('Y-m-d H:i'));
        self::assertSame($end, $to->setTimezone($berlin)->format('Y-m-d H:i'));
        self::assertSame('UTC', $from->getTimezone()->getName(), 'stored in UTC like every other time');
        self::assertSame($name, $period->name($from)->trans(new IdentityTranslator()));
    }

    public function testBronzeForTheWeekSilverForTheMonthGoldForTheYear(): void
    {
        self::assertSame(['Bronzener Pfeil', 'Silberner Pfeil', 'Goldener Pfeil'], array_map(static fn (AwardPeriod $p): string => $p->label(), AwardPeriod::cases()));
    }
}
