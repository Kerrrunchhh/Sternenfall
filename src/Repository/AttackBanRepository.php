<?php

namespace App\Repository;

use App\Entity\AttackBan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AttackBan> */
class AttackBanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttackBan::class);
    }

    /** @return list<AttackBan> the latest bans, newest first */
    public function findLatest(int $limit = 10): array
    {
        return $this->findBy([], ['startedAt' => 'DESC'], $limit);
    }

    /** @return list<AttackBan> bans that have not been lifted (some may have run out) */
    public function findNotLifted(): array
    {
        return $this->findBy(['liftedAt' => null], ['startedAt' => 'DESC']);
    }
}
