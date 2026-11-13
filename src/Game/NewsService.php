<?php

namespace App\Game;

use App\Entity\News;
use App\Entity\NewsRead;
use App\Entity\User;
use App\I18n\Raw;
use App\Repository\NewsRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** News: admins publish, players mark them read. */
final readonly class NewsService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NewsRepository $news,
        private ClockInterface $clock,
        private ?UserRepository $users = null,
        private ?GameMailer $mailer = null,
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

    /**
     * Tells every player who wants it (account settings, on by default) about news not yet
     * mailed – in his language. The welcome message is no news: it is never mailed.
     *
     * @return int how many news items were sent out
     */
    public function mailPending(): int
    {
        $pending = $this->news->findBy(['mailedAt' => null, 'welcome' => false], ['createdAt' => 'ASC']);
        if ([] === $pending || null === $this->users || null === $this->mailer) {
            return 0;
        }
        // Marked first: a crash halfway must not mail everybody twice.
        foreach ($pending as $news) {
            $news->markMailed($this->now());
        }
        $this->em->flush();

        $players = $this->users->findBy(['system' => false]);
        foreach ($pending as $news) {
            $this->mailer->broadcast($players, 'news', static fn (string $locale): Raw => new Raw($news->titleIn($locale)), static fn (string $locale): Raw => new Raw($news->bodyIn($locale)));
        }

        return \count($pending);
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
