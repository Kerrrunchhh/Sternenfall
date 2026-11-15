<?php

namespace App\Game;

use App\Entity\Award;
use App\Entity\ExperienceGain;
use App\Entity\User;
use App\I18n\Num;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;

use function Symfony\Component\Translation\t;

/**
 * Bronze, silver and golden arrows (AwardPeriod): once a week, month or year is over, the
 * player who gained the most combat experience in it is awarded – shown in the newspaper and
 * in his profile, and he gets a message. Cheap to call often (the result is remembered).
 */
final readonly class Awards
{
    public function __construct(
        private EntityManagerInterface $em,
        private Inbox $inbox,
        private LockFactory $locks,
        private ClockInterface $clock,
        private ?CacheInterface $cache = null,
    ) {
    }

    /** Awards every period that is over and not awarded yet (only the last one of each kind). */
    public function awardDue(): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        foreach (AwardPeriod::cases() as $period) {
            [$start, $end] = $period->lastCompleted($now);
            $check = fn (): bool => $this->award($period, $start, $end, $now);
            null === $this->cache ? $check() : $this->cache->get('award_'.$period->value.'_'.$start->getTimestamp(), static fn () => $check());
        }
    }

    /** @return list<Award> the newest first */
    public function recent(int $limit = 10): array
    {
        return $this->em->getRepository(Award::class)->findBy([], ['awardedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /** @return list<Award> a player's awards, gold first, then the newest */
    public function of(User $user): array
    {
        $awards = $this->em->getRepository(Award::class)->findBy(['user' => $user], ['periodStart' => 'DESC']);
        $rank = [AwardPeriod::Year->value => 0, AwardPeriod::Month->value => 1, AwardPeriod::Week->value => 2];
        usort($awards, static fn (Award $a, Award $b): int => [$rank[$a->getPeriod()->value], -$a->getPeriodStart()->getTimestamp()] <=> [$rank[$b->getPeriod()->value], -$b->getPeriodStart()->getTimestamp()]);

        return $awards;
    }

    /** The player with the most combat experience in [start, end), if anybody gained some. */
    public function leader(\DateTimeImmutable $start, \DateTimeImmutable $end): ?array
    {
        $rows = $this->em->createQuery('SELECT IDENTITY(g.user) AS id, SUM(g.amount) AS total FROM '.ExperienceGain::class.' g JOIN g.user u WHERE g.gainedAt >= :start AND g.gainedAt < :end AND u.system = false AND u.deactivatedAt IS NULL AND u.deleteAt IS NULL GROUP BY g.user ORDER BY total DESC')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setMaxResults(1)
            ->getArrayResult();
        $user = [] === $rows || $rows[0]['total'] < 0.5 ? null : $this->em->find(User::class, $rows[0]['id']);

        return null === $user ? null : ['user' => $user, 'experience' => (float) $rows[0]['total']];
    }

    private function award(AwardPeriod $period, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $now): bool
    {
        $lock = $this->locks->createLock('awards');
        if (!$lock->acquire()) {
            return false;
        }
        try {
            if (null !== $this->em->getRepository(Award::class)->findOneBy(['period' => $period, 'periodStart' => $start])) {
                return true;
            }
            $leader = $this->leader($start, $end);
            if (null === $leader) {
                return true;
            }
            $award = new Award($period, $start, $leader['user'], $leader['experience'], $now);
            $this->em->persist($award);
            $this->inbox->send($leader['user'], t('{icon} {award} für dich!', ['{icon}' => $period->icon(), '{award}' => t($period->label())]), t('Glückwunsch! Du hast im Zeitraum {period} die meiste Kampferfahrung gesammelt ({experience}) und bekommst den {award}. Er steht ab jetzt in deinem Profil und in der Zeitung.', ['{period}' => $period->name($start), '{experience}' => new Num($leader['experience']), '{award}' => t($period->label())]));
            $this->em->flush();

            return true;
        } finally {
            $lock->release();
        }
    }
}
