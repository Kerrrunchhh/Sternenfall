<?php

namespace App\I18n;

/**
 * The time zone times are shown in. The game itself keeps and computes every time as
 * before; only showing converts – for the viewer of a page (set per request by the
 * TimezoneListener) or for the recipient of a message or mail (Inbox, GameMailer).
 * IANA zones (Europe/Berlin, America/New_York …) carry their daylight saving rules,
 * so clock changes and the turn of the year come out right by themselves.
 */
final class DisplayZone
{
    public const string DEFAULT = 'Europe/Berlin';

    private static ?\DateTimeZone $current = null;

    public static function get(): \DateTimeZone
    {
        return self::$current ??= new \DateTimeZone(self::DEFAULT);
    }

    public static function set(string $zone): void
    {
        self::$current = new \DateTimeZone(self::isValid($zone) ? $zone : self::DEFAULT);
    }

    /**
     * Runs $render with times shown in $zone, then switches back.
     *
     * @template T
     *
     * @param callable(): T $render
     *
     * @return T
     */
    public static function with(string $zone, callable $render): mixed
    {
        $previous = self::get();
        self::set($zone);
        try {
            return $render();
        } finally {
            self::$current = $previous;
        }
    }

    public static function isValid(string $zone): bool
    {
        return \in_array($zone, \DateTimeZone::listIdentifiers(), true);
    }

    /** The moment converted for showing. */
    public static function local(\DateTimeInterface $moment): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($moment)->setTimezone(self::get());
    }
}
