<?php

namespace App\Tests\Game;

use App\EventListener\MailCounter;
use App\Repository\MailStatRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Every mail is counted per recipient – blind copies too – and a refused one as failed. */
class MailCounterTest extends TestCase
{
    /** @var list<array{string, int, int, int}> */
    private array $added = [];

    public function testRecipientsAreCountedPerHour(): void
    {
        $counter = new MailCounter($this->stats(), new MockClock('2026-11-16 14:37:00 Europe/Berlin'), new NullLogger());
        $broadcast = (new Email())->from('spiel@example.org')->to('spiel@example.org')->bcc('a@example.org', 'b@example.org', 'c@example.org')->subject('News')->text('…');

        $counter->onSent(new SentMessageEvent(new SentMessage($broadcast, new Envelope(new Address('spiel@example.org'), [new Address('spiel@example.org'), new Address('a@example.org'), new Address('b@example.org'), new Address('c@example.org')]))));
        $counter->onFailed(new FailedMessageEvent($broadcast, new \RuntimeException('limit')));

        self::assertSame([['2026-11-16 13', 1, 4, 0], ['2026-11-16 13', 0, 0, 4]], $this->added, 'UTC hour; sent: 1 mail to 4, failed: 4 recipients');
    }

    public function testCountingNeverStopsAMail(): void
    {
        $stats = $this->createStub(MailStatRepository::class);
        $stats->method('add')->willThrowException(new \RuntimeException('database down'));
        $counter = new MailCounter($stats, new MockClock(), new NullLogger());

        $counter->onFailed(new FailedMessageEvent((new Email())->from('a@example.org')->to('b@example.org')->text('x'), new \RuntimeException('x')));
        $this->addToAssertionCount(1);
    }

    private function stats(): MailStatRepository
    {
        $stats = $this->createStub(MailStatRepository::class);
        $stats->method('add')->willReturnCallback(function (\DateTimeImmutable $hour, int $mails, int $recipients, int $failed): void {
            $this->added[] = [$hour->format('Y-m-d H'), $mails, $recipients, $failed];
        });

        return $stats;
    }
}
