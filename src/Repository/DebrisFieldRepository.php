<?php

namespace App\Repository;

use App\Entity\DebrisField;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DebrisField>
 */
class DebrisFieldRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DebrisField::class);
    }

    public function findAt(int $galaxy, int $system, int $position): ?DebrisField
    {
        return $this->findOneBy(['galaxy' => $galaxy, 'system' => $system, 'position' => $position]);
    }

    /** @return array<int, DebrisField> position => field */
    public function findInSystem(int $galaxy, int $system): array
    {
        $fields = [];
        foreach ($this->findBy(['galaxy' => $galaxy, 'system' => $system]) as $field) {
            $fields[$field->getPosition()] = $field;
        }

        return $fields;
    }
}
