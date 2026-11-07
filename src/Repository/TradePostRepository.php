<?php

namespace App\Repository;

use App\Entity\TradePost;
use App\Entity\User;
use App\Game\TradeDeal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradePost>
 */
class TradePostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradePost::class);
    }

    public function findAt(int $galaxy, int $system, int $position): ?TradePost
    {
        return $this->findOneBy(['galaxy' => $galaxy, 'system' => $system, 'position' => $position]);
    }

    /** @return list<TradePost> */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['galaxy' => 'ASC', 'system' => 'ASC', 'position' => 'ASC']);
    }

    /** @return list<TradePost> */
    public function findInSystem(int $galaxy, int $system): array
    {
        return $this->findBy(['galaxy' => $galaxy, 'system' => $system]);
    }

    /** Active posts only: abandoned ones don't count towards the trade network limit. */
    public function countByOwner(User $owner): int
    {
        return $this->count(['owner' => $owner, 'active' => true]);
    }

    /**
     * Average price per good over all trade posts that offer it (price set and in stock).
     *
     * @return array<string, array{average: float, posts: int}>
     */
    public function averagePrices(): array
    {
        $sums = [];
        foreach ($this->findBy(['active' => true]) as $post) {
            foreach (TradeDeal::goods() as $good) {
                if ($post->getPrice($good) > 0 && $post->getStock($good) >= 1) {
                    $sums[$good][] = $post->getPrice($good);
                }
            }
        }

        $averages = [];
        foreach (TradeDeal::goods() as $good) {
            if (isset($sums[$good])) {
                $averages[$good] = ['average' => array_sum($sums[$good]) / \count($sums[$good]), 'posts' => \count($sums[$good])];
            }
        }

        return $averages;
    }
}
