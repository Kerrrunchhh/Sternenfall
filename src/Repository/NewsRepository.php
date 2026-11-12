<?php

namespace App\Repository;

use App\Entity\News;
use App\Entity\NewsRead;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<News> */
class NewsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, News::class);
    }

    /** @return list<News> all news, newest first (the archive) */
    public function findNewestFirst(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * What the player still has to read, oldest first: the welcome message and news that
     * appeared after he joined.
     *
     * @return list<News>
     */
    public function findUnreadFor(User $user): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.welcome = true OR n.createdAt >= :joined')
            ->andWhere('NOT EXISTS (SELECT r.id FROM '.NewsRead::class.' r WHERE r.news = n AND r.user = :user)')
            ->setParameter('joined', $user->getCreatedAt())
            ->setParameter('user', $user)
            ->orderBy('n.welcome', 'DESC')
            ->addOrderBy('n.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array<int, true> ids of the news the player marked read */
    public function readIds(User $user): array
    {
        $ids = $this->getEntityManager()->createQuery('SELECT IDENTITY(r.news) FROM '.NewsRead::class.' r WHERE r.user = :user')
            ->setParameter('user', $user)
            ->getSingleColumnResult();

        return array_fill_keys(array_map('intval', $ids), true);
    }
}
