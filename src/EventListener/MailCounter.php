<?php

namespace App\EventListener;

use App\Repository\MailStatRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Counts every mail the game sends (and every one the mail server refuses), per hour and
 * recipient – to see how close it gets to the mail provider's daily limit.
 */
final readonly class MailCounter
{
    public function __construct(
        private MailStatRepository $stats,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onSent(SentMessageEvent $event): void
    {
        $this->count(\count($event->getMessage()->getEnvelope()->getRecipients()), false);
    }

    #[AsEventListener]
    public function onFailed(FailedMessageEvent $event): void
    {
        $this->count(self::recipients($event->getMessage()), true);
    }

    private function count(int $recipients, bool $failed): void
    {
        $hour = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
        try {
            $this->stats->add($hour, $failed ? 0 : 1, $failed ? 0 : $recipients, $failed ? $recipients : 0);
        } catch (\Throwable $e) {
            // Counting must never stop a mail.
            $this->logger->warning('Could not count a mail: {error}', ['error' => $e->getMessage()]);
        }
    }

    private static function recipients(RawMessage $message): int
    {
        return $message instanceof Email ? \count($message->getTo()) + \count($message->getCc()) + \count($message->getBcc()) : 1;
    }
}
