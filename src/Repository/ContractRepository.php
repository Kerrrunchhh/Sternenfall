<?php

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contract>
 */
class ContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contract::class);
    }

    /** @return list<Contract> */
    public function findOpen(): array
    {
        return $this->findBy(['status' => Contract::OPEN], ['createdAt' => 'DESC']);
    }

    /** @return list<Contract> contracts the player gave or runs */
    public function findOf(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.client = :u OR c.contractor = :u')->setParameter('u', $user)
            ->orderBy('c.createdAt', 'DESC')->setMaxResults(100)->getQuery()->getResult();
    }

    /** @return list<Contract> running contracts whose time is up */
    public function findDue(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :running AND c.endsAt <= :now')
            ->setParameter('running', Contract::RUNNING)->setParameter('now', $now)
            ->getQuery()->getResult();
    }

    /** @return list<Contract> running contracts on a slot range of a system */
    public function findRunningIn(int $galaxy, int $system): array
    {
        return $this->findBy(['status' => Contract::RUNNING, 'galaxy' => $galaxy, 'system' => $system]);
    }
}
