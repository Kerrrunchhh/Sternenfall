<?php

namespace App\Game;

use App\I18n\Raw;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * The arrows for the most combat experience: bronze for a week, silver for a month, gold for
 * a year. Periods follow the calendar in German time (weeks from Monday).
 */
enum AwardPeriod: string
{
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public const string ZONE = 'Europe/Berlin';

    public function label(): string
    {
        return match ($this) {
            self::Week => 'Bronzener Pfeil',
            self::Month => 'Silberner Pfeil',
            self::Year => 'Goldener Pfeil',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Week => '🥉',
            self::Month => '🥈',
            self::Year => '🥇',
        };
    }

    /** What it is for, for the profile and the newspaper. */
    public function description(): string
    {
        return match ($this) {
            self::Week => 'Die meiste Kampferfahrung der Woche',
            self::Month => 'Die meiste Kampferfahrung des Monats',
            self::Year => 'Die meiste Kampferfahrung des Jahres',
        };
    }

    /**
     * The last period that is over at the moment: [start, end) – in UTC, the borders at midnight German time.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    public function lastCompleted(\DateTimeImmutable $now): array
    {
        $local = $now->setTimezone(new \DateTimeZone(self::ZONE));
        $end = match ($this) {
            self::Week => $local->modify('monday this week')->setTime(0, 0),
            self::Month => $local->modify('first day of this month')->setTime(0, 0),
            self::Year => $local->setDate((int) $local->format('Y'), 1, 1)->setTime(0, 0),
        };
        $start = match ($this) {
            self::Week => $end->modify('-1 week'),
            self::Month => $end->modify('-1 month'),
            self::Year => $end->modify('-1 year'),
        };

        // The database keeps times without a zone, in UTC like everything else.
        $utc = new \DateTimeZone('UTC');

        return [$start->setTimezone($utc), $end->setTimezone($utc)];
    }

    /** "KW 46/2026", "11/2026", "2026" – the period an award was given for. */
    public function name(\DateTimeImmutable $start): TranslatableInterface
    {
        $local = $start->setTimezone(new \DateTimeZone(self::ZONE));

        return match ($this) {
            self::Week => t('KW {week}/{year}', ['{week}' => (int) $local->format('W'), '{year}' => $local->format('o')]),
            self::Month => new Raw($local->format('m/Y')),
            self::Year => new Raw($local->format('Y')),
        };
    }
}
