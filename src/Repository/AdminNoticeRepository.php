<?php

namespace App\Repository;

use App\Entity\AdminNotice;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AdminNotice> */
class AdminNoticeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminNotice::class);
    }

    /** @return list<AdminNotice> not mailed yet, oldest first */
    public function findUnsent(): array
    {
        return $this->findBy(['sentAt' => null], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    /** How many notices of the kind the player caused since then (to stop flooding). */
    public function countSince(User $user, string $kind, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('n')->select('COUNT(n.id)')
            ->where('n.user = :user AND n.kind = :kind AND n.createdAt >= :since')
            ->setParameter('user', $user)->setParameter('kind', $kind)->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<User> admins with an address to mail to */
    public function findAdmins(): array
    {
        return $this->getEntityManager()->createQuery('SELECT u FROM '.User::class.' u WHERE u.roles LIKE :role AND u.email IS NOT NULL')
            ->setParameter('role', '%"ROLE_ADMIN"%')
            ->getResult();
    }
}
