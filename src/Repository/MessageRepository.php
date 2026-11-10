<?php

namespace App\Repository;

use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function countUnread(User $recipient): int
    {
        return $this->count(['recipient' => $recipient, 'isRead' => false]);
    }

    /** @return list<Message> */
    public function findLatest(User $recipient, int $limit = 100, int $offset = 0): array
    {
        return $this->findBy(['recipient' => $recipient], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit, $offset);
    }

    public function countFor(User $recipient): int
    {
        return $this->count(['recipient' => $recipient]);
    }

    /** Messages are kept for 30 days. */
    public const int KEEP_DAYS = 30;

    public function deleteOlderThan(\DateTimeImmutable $limit): int
    {
        return $this->createQueryBuilder('m')
            ->delete()
            ->where('m.createdAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->execute();
    }

    public function markAllRead(User $recipient): void
    {
        $this->createQueryBuilder('m')
            ->update()
            ->set('m.isRead', 'true')
            ->where('m.recipient = :recipient')
            ->setParameter('recipient', $recipient)
            ->getQuery()
            ->execute();
    }
}
