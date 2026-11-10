<?php

namespace App\Repository;

use App\Entity\Ticker;
use App\Entity\TickerPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TickerPost>
 */
class TickerPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TickerPost::class);
    }

    /** @return list<TickerPost> newest first */
    public function findLatest(Ticker $ticker, int $limit = 100): array
    {
        return $this->findBy(['ticker' => $ticker], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
