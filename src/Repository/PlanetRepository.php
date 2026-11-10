<?php

namespace App\Repository;

use App\Entity\Planet;
use App\Game\LifeForm;
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

    /** @return list<Planet> planets being abandoned whose time is up */
    public function findAbandonedBefore(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.abandonAt IS NOT NULL AND p.abandonAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
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
    /**
     * Planets of players with the given life form within a range of systems.
     *
     * @return list<Planet>
     */
    public function findOfLifeFormNear(LifeForm $lifeForm, int $galaxy, int $system, int $range): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.owner', 'o')
            ->where('o.lifeForm = :lifeForm AND p.galaxy = :galaxy AND p.system BETWEEN :from AND :to')
            ->setParameter('lifeForm', $lifeForm)
            ->setParameter('galaxy', $galaxy)
            ->setParameter('from', $system - $range)
            ->setParameter('to', $system + $range)
            ->getQuery()
            ->getResult();
    }

    public function findInSystem(int $galaxy, int $system): array
    {
        return $this->findBy(['galaxy' => $galaxy, 'system' => $system], ['position' => 'ASC']);
    }
}
