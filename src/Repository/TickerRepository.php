<?php

namespace App\Repository;

use App\Entity\Ticker;
use App\Entity\TickerMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticker>
 */
class TickerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticker::class);
    }

    /** @return list<Ticker> tickers the player belongs to */
    public function findJoined(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.members', 'm')
            ->where('m.user = :u AND m.acceptedAt IS NOT NULL')
            ->setParameter('u', $user)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<TickerMember> invitations waiting for the player */
    public function findInvitations(User $user): array
    {
        return $this->getEntityManager()->getRepository(TickerMember::class)->findBy(['user' => $user, 'acceptedAt' => null], ['invitedAt' => 'ASC']);
    }

    public function countInvitations(User $user): int
    {
        return $this->getEntityManager()->getRepository(TickerMember::class)->count(['user' => $user, 'acceptedAt' => null]);
    }
}
