<?php

namespace App\Repository;

use App\Entity\Bookmark;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Bookmark> */
class BookmarkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bookmark::class);
    }

    /** @return list<Bookmark> in the player's own order */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneAt(User $owner, int $galaxy, int $system, int $position): ?Bookmark
    {
        return $this->findOneBy(['owner' => $owner, 'galaxy' => $galaxy, 'system' => $system, 'position' => $position]);
    }

    /** @return array<int, Bookmark> position => bookmark of one system */
    public function findInSystem(User $owner, int $galaxy, int $system): array
    {
        $byPosition = [];
        foreach ($this->findBy(['owner' => $owner, 'galaxy' => $galaxy, 'system' => $system]) as $bookmark) {
            $byPosition[$bookmark->getPosition()] = $bookmark;
        }

        return $byPosition;
    }
}
