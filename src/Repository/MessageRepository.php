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
    public function findLatest(User $recipient, int $limit = 100): array
    {
        return $this->findBy(['recipient' => $recipient], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
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
