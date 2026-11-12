<?php

namespace App\Repository;

use App\Entity\TransportRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransportRequest>
 */
class TransportRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransportRequest::class);
    }

    /** @return list<TransportRequest> */
    public function findOpen(): array
    {
        return $this->findBy(['status' => TransportRequest::OPEN], ['createdAt' => 'ASC']);
    }

    /** @return list<TransportRequest> */
    public function findOf(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.requester = :u OR r.trader = :u')->setParameter('u', $user)
            ->orderBy('r.createdAt', 'DESC')->setMaxResults(100)->getQuery()->getResult();
    }

    /** Requests of a player still running: open or on their way. */
    public function countActive(User $user): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.requester = :user AND r.status IN (:running)')
            ->setParameter('user', $user)
            ->setParameter('running', [TransportRequest::OPEN, TransportRequest::ASSIGNED])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
