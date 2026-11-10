<?php

namespace App\Game;

use App\Entity\User;
use App\Repository\ContactRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Online status: a player counts as online for a few minutes after his last page view.
 * Only alliance mates and quick contacts see it.
 */
final class Presence
{
    public const int ONLINE_MINUTES = 5;
    /** Seconds between two writes of the last-seen time. */
    private const int TOUCH_INTERVAL = 60;

    /** @var array<int, array<int, bool>> viewer id => contact ids, cached per request */
    private array $contactCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContactRepository $contacts,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Remembers that the player is active (at most once a minute, without touching other entities). */
    public function touch(User $user): void
    {
        $now = $this->now();
        $last = $user->getLastSeenAt();
        if (null !== $last && $now->getTimestamp() - $last->getTimestamp() < self::TOUCH_INTERVAL) {
            return;
        }
        $user->markSeen($now, !$this->isOnline($user));
        $this->connection->executeStatement(
            'UPDATE `user` SET last_seen_at = ?, online_since = ? WHERE id = ?',
            [$now, $user->getOnlineSince(), $user->getId()],
            [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, ParameterType::INTEGER],
        );
    }

    public function isOnline(User $user): bool
    {
        $last = $user->getLastSeenAt();

        return null !== $last && $this->now()->getTimestamp() - $last->getTimestamp() < self::ONLINE_MINUTES * 60;
    }

    /** "online (<1h)", "online (3h)", "offline (<1h)", "offline (3h)" or "noch nie online". */
    public function describe(User $user): string
    {
        $last = $user->getLastSeenAt();
        if (null === $last) {
            return 'noch nie online';
        }
        if ($this->isOnline($user)) {
            $hours = intdiv($this->now()->getTimestamp() - ($user->getOnlineSince() ?? $last)->getTimestamp(), 3600);

            return 0 === $hours ? 'online (<1h)' : \sprintf('online (%sh)', number_format($hours, 0, ',', '.'));
        }
        $hours = intdiv($this->now()->getTimestamp() - $last->getTimestamp(), 3600);

        return 0 === $hours ? 'offline (<1h)' : \sprintf('offline (%sh)', number_format($hours, 0, ',', '.'));
    }

    /** Whether the viewer may see the other's status: same alliance or quick contact. */
    public function isVisibleTo(User $viewer, User $other): bool
    {
        if ($viewer->isSameAs($other)) {
            return true;
        }
        if (null !== $viewer->getAlliance() && null !== $other->getAlliance() && $viewer->getAlliance()->getId() === $other->getAlliance()->getId()) {
            return true;
        }

        return $this->contactIds($viewer)[$other->getId()] ?? false;
    }

    /** @return array<int, bool> */
    private function contactIds(User $viewer): array
    {
        $id = (int) $viewer->getId();
        if (!isset($this->contactCache[$id])) {
            $this->contactCache[$id] = [];
            foreach ($this->contacts->findAccepted($viewer) as $contact) {
                $this->contactCache[$id][(int) $contact->other($viewer)->getId()] = true;
            }
        }

        return $this->contactCache[$id];
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
