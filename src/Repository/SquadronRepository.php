<?php

namespace App\Repository;

use App\Entity\Squadron;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Squadron> */
class SquadronRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Squadron::class);
    }

    /** @return list<Squadron> */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['name' => 'ASC']);
    }
}
