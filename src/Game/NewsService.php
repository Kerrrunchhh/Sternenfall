<?php

namespace App\Game;

use App\Entity\News;
use App\Entity\NewsRead;
use App\Entity\User;
use App\Repository\NewsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** News: admins publish, players mark them read. */
final readonly class NewsService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NewsRepository $news,
        private ClockInterface $clock,
    ) {
    }

    public function create(): News
    {
        return new News($this->now());
    }

    /** Saves a new or edited news item. */
    public function save(News $news): void
    {
        if (null === $news->getId()) {
            $this->em->persist($news);
        } else {
            $news->touch($this->now());
        }
        $this->em->flush();
    }

    public function delete(News $news): void
    {
        $this->em->remove($news);
        $this->em->flush();
    }

    public function markRead(User $user, News $news): void
    {
        if (!isset($this->news->readIds($user)[(int) $news->getId()])) {
            $this->em->persist(new NewsRead($news, $user, $this->now()));
            $this->em->flush();
        }
    }

    public function markAllRead(User $user): void
    {
        foreach ($this->news->findUnreadFor($user) as $news) {
            $this->em->persist(new NewsRead($news, $user, $this->now()));
        }
        $this->em->flush();
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
