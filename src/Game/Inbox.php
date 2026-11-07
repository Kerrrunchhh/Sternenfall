<?php

namespace App\Game;

use App\Entity\Message;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Delivers system messages to players (persisted, flushed by the caller).
 */
final readonly class Inbox
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function send(User $recipient, string $subject, string $body): void
    {
        $this->em->persist(new Message($recipient, $subject, $body, \DateTimeImmutable::createFromInterface($this->clock->now())));
    }
}
