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

    /** @return list<User> admins with an address to mail to */
    public function findAdmins(): array
    {
        return $this->getEntityManager()->createQuery('SELECT u FROM '.User::class.' u WHERE u.roles LIKE :role AND u.email IS NOT NULL')
            ->setParameter('role', '%"ROLE_ADMIN"%')
            ->getResult();
    }
}
