<?php

namespace App\Repository;

use App\Entity\PlayerMessage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayerMessage>
 */
class PlayerMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerMessage::class);
    }

    /** @return list<PlayerMessage> */
    public function findInbox(User $user, int $limit = 200, int $offset = 0): array
    {
        return $this->findBy(['recipient' => $user, 'deletedByRecipient' => false], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit, $offset);
    }

    public function countInbox(User $user): int
    {
        return $this->count(['recipient' => $user, 'deletedByRecipient' => false]);
    }

    public function countSent(User $user): int
    {
        return $this->count(['sender' => $user, 'deletedBySender' => false]);
    }

    /** @return list<PlayerMessage> */
    public function findSent(User $user, int $limit = 200, int $offset = 0): array
    {
        return $this->findBy(['sender' => $user, 'deletedBySender' => false], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit, $offset);
    }

    public function countUnread(User $user): int
    {
        return $this->count(['recipient' => $user, 'deletedByRecipient' => false, 'isRead' => false]);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<PlayerMessage>
     */
    public function findByIds(array $ids): array
    {
        return [] === $ids ? [] : $this->findBy(['id' => $ids]);
    }
}
