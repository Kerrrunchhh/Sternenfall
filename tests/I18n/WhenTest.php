<?php

namespace App\Tests\I18n;

use App\Controller\AccountController;
use App\Entity\Message;
use App\Entity\User;
use App\Game\Inbox;
use App\I18n\DisplayZone;
use App\I18n\When;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\IdentityTranslator;

use function Symfony\Component\Translation\t;

class WhenTest extends TestCase
{
    protected function tearDown(): void
    {
        DisplayZone::set(DisplayZone::DEFAULT);
    }

    /** @return iterable<string, array{string, string, string}> UTC moment, zone, expected local time */
    public static function moments(): iterable
    {
        // Spring forward in Europe (28.03.2027, 2:00 → 3:00): one second apart, one hour on the clock.
        yield 'Berlin before spring forward' => ['2027-03-28 00:59:59', 'Europe/Berlin', '28.03.2027 01:59:59'];
        yield 'Berlin after spring forward' => ['2027-03-28 01:00:00', 'Europe/Berlin', '28.03.2027 03:00:00'];
        // Fall back (25.10.2026, 3:00 → 2:00): the hour from 2 to 3 comes twice.
        yield 'Berlin before fall back' => ['2026-10-25 00:59:59', 'Europe/Berlin', '25.10.2026 02:59:59'];
        yield 'Berlin after fall back' => ['2026-10-25 01:00:00', 'Europe/Berlin', '25.10.2026 02:00:00'];
        // The US switches on other days than Europe (14.03.2027).
        yield 'New York after spring forward' => ['2027-03-14 07:00:00', 'America/New_York', '14.03.2027 03:00:00'];
        // New Year: in Berlin and Tokyo it is already 2027, in New York still 2026.
        yield 'New Year Berlin' => ['2026-12-31 23:30:00', 'Europe/Berlin', '01.01.2027 00:30:00'];
        yield 'New Year Tokyo' => ['2026-12-31 23:30:00', 'Asia/Tokyo', '01.01.2027 08:30:00'];
        yield 'New Year New York' => ['2026-12-31 23:30:00', 'America/New_York', '31.12.2026 18:30:00'];
        yield 'New Year Honolulu, next day' => ['2027-01-01 09:59:00', 'Pacific/Honolulu', '31.12.2026 23:59:00'];
        // Southern hemisphere: summer time in January.
        yield 'Sydney in summer' => ['2027-01-15 00:00:00', 'Australia/Sydney', '15.01.2027 11:00:00'];
        // Half hours exist too.
        yield 'India' => ['2027-01-15 00:00:00', 'Asia/Kolkata', '15.01.2027 05:30:00'];
    }

    #[DataProvider('moments')]
    public function testShowsTheMomentInTheChosenZone(string $utc, string $zone, string $expected): void
    {
        DisplayZone::set($zone);

        self::assertSame($expected, (new When(new \DateTimeImmutable($utc, new \DateTimeZone('UTC')), 'd.m.Y H:i:s'))->trans(new IdentityTranslator()));
    }

    public function testRendersWhenTranslatedNotWhenCreated(): void
    {
        $when = new When(new \DateTimeImmutable('2027-01-15 12:00:00 UTC'));

        DisplayZone::set('America/New_York');
        self::assertSame('15.01.2027 07:00', $when->trans(new IdentityTranslator()));
        DisplayZone::set('Asia/Tokyo');
        self::assertSame('15.01.2027 21:00', $when->trans(new IdentityTranslator()));
    }

    public function testWithSwitchesBackEvenAfterAnError(): void
    {
        DisplayZone::set('Asia/Tokyo');
        try {
            DisplayZone::with('America/New_York', static fn () => throw new \RuntimeException());
        } catch (\RuntimeException) {
        }

        self::assertSame('Asia/Tokyo', DisplayZone::get()->getName());
    }

    public function testUnknownZonesFallBackOrAreRefused(): void
    {
        DisplayZone::set('Mars/Olympus_Mons');
        self::assertSame(DisplayZone::DEFAULT, DisplayZone::get()->getName());

        $this->expectException(\InvalidArgumentException::class);
        (new User('Pilotin', new \DateTimeImmutable()))->setTimezone('Mars/Olympus_Mons');
    }

    public function testMessagesShowTimesInTheRecipientsZone(): void
    {
        DisplayZone::set('Europe/Berlin'); // the zone of whoever caused the message
        $messages = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$messages): void {
            $messages[] = $o;
        });
        $recipient = new User('Pilotin', new \DateTimeImmutable());
        $recipient->setTimezone('America/New_York');

        (new Inbox($em, new MockClock()))->send($recipient, t('Angriff'), t('Einschlag: {value}', ['{value}' => new When(new \DateTimeImmutable('2026-12-31 23:30:00 UTC'))]));

        self::assertInstanceOf(Message::class, $messages[0]);
        self::assertSame('Einschlag: 31.12.2026 18:30', $messages[0]->getBody());
        self::assertSame('Europe/Berlin', DisplayZone::get()->getName(), 'switched back afterwards');
    }

    public function testTheZoneListShowsTheOffsetOfTheDay(): void
    {
        self::assertSame('Europe/Berlin (UTC+01:00)', AccountController::timezones(new \DateTimeImmutable('2027-01-15 12:00 UTC'))['Europe']['Europe/Berlin']);
        self::assertSame('Europe/Berlin (UTC+02:00)', AccountController::timezones(new \DateTimeImmutable('2027-07-15 12:00 UTC'))['Europe']['Europe/Berlin']);
        self::assertSame('America/New York (UTC-05:00)', AccountController::timezones(new \DateTimeImmutable('2027-01-15 12:00 UTC'))['America']['America/New_York']);
    }
}
