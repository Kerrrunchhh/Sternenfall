<?php

namespace App\Repository;

use App\Entity\Bounty;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Bounty> */
class BountyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bounty::class);
    }

    /** @return list<Bounty> open bounties, the highest reward first */
    public function findOpen(): array
    {
        return $this->findBy(['status' => Bounty::OPEN], ['reward' => 'DESC', 'createdAt' => 'ASC']);
    }

    /** @return list<Bounty> */
    public function findOpenOn(User $target): array
    {
        return $this->findBy(['status' => Bounty::OPEN, 'target' => $target], ['createdAt' => 'ASC']);
    }

    /** @return list<Bounty> bounties the player put up, newest first */
    public function findOf(User $client): array
    {
        return $this->findBy(['client' => $client], ['createdAt' => 'DESC'], 50);
    }

    /** @return list<Bounty> open bounties whose time is up */
    public function findDue(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :open')
            ->andWhere('b.endsAt <= :now')
            ->setParameter('open', Bounty::OPEN)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
