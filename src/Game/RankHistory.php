<?php

namespace App\Game;

use App\Entity\HighscoreSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Where players stood in the highscores 24 hours ago: once an hour every ranked player's
 * values are saved; the places of back then are worked out from them for any sorting and
 * life form. Snapshots older than KEEP_HOURS are deleted.
 */
final readonly class RankHistory
{
    public const int HOURS = 24;
    private const int KEEP_HOURS = 72;
    /** The comparison snapshot may be this much older than 24 hours (an hour nobody played). */
    private const int TOLERANCE_HOURS = 6;

    public function __construct(
        private EntityManagerInterface $em,
        private Highscores $highscores,
        private LockFactory $locks,
        private ClockInterface $clock,
        private ?CacheInterface $cache = null,
    ) {
    }

    /** Takes this hour's snapshot unless it exists; cheap to call often (remembered in the cache). */
    public function recordHourly(): void
    {
        // In the app's time zone, like every other date column (stored without a zone).
        $hour = $this->now();
        $hour = $hour->setTime((int) $hour->format('G'), 0);
        $record = fn (): bool => $this->record($hour);
        null === $this->cache ? $record() : $this->cache->get('highscore_snapshot_'.$hour->format('YmdH'), static fn () => $record());
    }

    /**
     * Places 24 hours ago in the given ranking; players who weren't ranked then are missing.
     *
     * @return array<int, int> user id => place
     */
    public function placesThen(string $sort, ?LifeForm $lifeForm = null): array
    {
        $snapshot = $this->snapshotFrom(self::HOURS);
        if (null === $snapshot) {
            return [];
        }
        $rows = [];
        foreach ($snapshot->getPlayers() as $id => $values) {
            if (null === $lifeForm || $values['lifeForm'] === $lifeForm->value) {
                $rows[] = ['id' => (int) $id] + $values;
            }
        }

        return array_column(Highscores::rank($rows, $sort), 'rank', 'id');
    }

    /**
     * Adds 'change' to every row: places won (positive) or lost (negative) in 24 hours,
     * null if the player wasn't ranked back then.
     *
     * @template T of array{rank: int, user: \App\Entity\User}
     *
     * @param list<T> $rows
     *
     * @return list<T&array{change: int|null}>
     */
    public function withChanges(array $rows, string $sort, ?LifeForm $lifeForm = null): array
    {
        $then = $this->placesThen($sort, $lifeForm);

        return array_map(static function (array $row) use ($then): array {
            $before = $then[$row['user']->getId()] ?? null;

            return $row + ['change' => null === $before ? null : $before - $row['rank']];
        }, $rows);
    }

    private function record(\DateTimeImmutable $hour): bool
    {
        $lock = $this->locks->createLock('highscore_snapshot');
        if (!$lock->acquire()) {
            return false;
        }
        try {
            $repository = $this->em->getRepository(HighscoreSnapshot::class);
            if (null === $repository->findOneBy(['takenAt' => $hour])) {
                $this->em->persist(new HighscoreSnapshot($hour, $this->highscores->values()));
                $this->em->flush();
            }
            $this->em->createQuery('DELETE FROM '.HighscoreSnapshot::class.' s WHERE s.takenAt < :before')
                ->setParameter('before', $hour->modify('-'.self::KEEP_HOURS.' hours'))
                ->execute();

            return true;
        } finally {
            $lock->release();
        }
    }

    /** The newest snapshot that is at least $hours old (and not much older). */
    private function snapshotFrom(int $hours): ?HighscoreSnapshot
    {
        $at = $this->now()->modify('-'.$hours.' hours');

        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(HighscoreSnapshot::class, 's')
            ->where('s.takenAt <= :at AND s.takenAt >= :oldest')
            ->setParameter('at', $at)
            ->setParameter('oldest', $at->modify('-'.self::TOLERANCE_HOURS.' hours'))
            ->orderBy('s.takenAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
