<?php

namespace App\Repository;

use App\Entity\Planet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Planet>
 */
class PlanetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Planet::class);
    }

    /** The planet occupying a slot, including slots that are part of a fusion. */
    public function findAt(int $galaxy, int $system, int $position): ?Planet
    {
        return $this->createQueryBuilder('p')
            ->where('p.galaxy = :galaxy AND p.system = :system')
            ->andWhere('p.position <= :position AND p.position + p.fusionSize - 1 >= :position')
            ->setParameter('galaxy', $galaxy)
            ->setParameter('system', $system)
            ->setParameter('position', $position)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function isOccupied(int $galaxy, int $system, int $position): bool
    {
        return null !== $this->findAt($galaxy, $system, $position);
    }

    /** @return list<Planet> all planets of a system, top to bottom */
    public function findInSystem(int $galaxy, int $system): array
    {
        return $this->findBy(['galaxy' => $galaxy, 'system' => $system], ['position' => 'ASC']);
    }
}
