<?php

namespace App\Repository;

use App\Entity\BattleReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BattleReport>
 */
class BattleReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BattleReport::class);
    }

    /**
     * Reports created in a time window, newest first (the newspaper).
     *
     * @return list<BattleReport>
     */
    public function findPublished(\DateTimeImmutable $from, \DateTimeImmutable $until, int $offset, int $limit): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.createdAt >= :from AND r.createdAt <= :until')
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPublished(\DateTimeImmutable $from, \DateTimeImmutable $until): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.createdAt >= :from AND r.createdAt <= :until')
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Removes reports older than their availability. */
    public function deleteCreatedBefore(\DateTimeImmutable $limit): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.createdAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->execute();
    }
}
