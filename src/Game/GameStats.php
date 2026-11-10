<?php

namespace App\Game;

use App\Entity\DeletedAccount;
use App\Entity\ExperienceGain;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Figures for the status page and the start page. */
final readonly class GameStats
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{players: int, planets: int, slots: int, colonized_slots: int, ownerless: int, transports: int, attacks: int, signups: int, signoffs: int}
     */
    public function status(): array
    {
        $since = $this->now()->modify('-24 hours');
        $scalar = fn (string $dql, array $params = []): int => (int) $this->em->createQuery($dql)->setParameters($params)->getSingleScalarResult();

        return [
            'players' => $scalar('SELECT COUNT(u.id) FROM '.User::class.' u WHERE u.system = false'),
            'planets' => $scalar('SELECT COUNT(p.id) FROM '.Planet::class.' p'),
            'colonized_slots' => $scalar('SELECT COALESCE(SUM(p.fusionSize), 0) FROM '.Planet::class.' p'),
            'ownerless' => $scalar('SELECT COUNT(p.id) FROM '.Planet::class.' p JOIN p.owner o WHERE o.system = true'),
            'slots' => $this->universeSlots(),
            'transports' => $scalar('SELECT COUNT(f.id) FROM '.Fleet::class.' f WHERE f.mission IN (:missions) AND f.returning = false', ['missions' => [FleetMission::Transport, FleetMission::TransportStation, FleetMission::Trade, FleetMission::Supply, FleetMission::Pickup]]),
            'attacks' => $scalar('SELECT COUNT(f.id) FROM '.Fleet::class.' f WHERE f.mission = :attack AND f.returning = false', ['attack' => FleetMission::Attack]),
            'signups' => $scalar('SELECT COUNT(u.id) FROM '.User::class.' u WHERE u.createdAt >= :since AND u.system = false', ['since' => $since]),
            'signoffs' => $scalar('SELECT COUNT(d.id) FROM '.DeletedAccount::class.' d WHERE d.deletedAt >= :since', ['since' => $since]),
        ];
    }

    /**
     * Players with the most combat experience gained in the last 24 hours.
     *
     * @return list<array{user: User, experience: float}>
     */
    public function topExperienceToday(int $limit = 10): array
    {
        $rows = $this->em->createQuery('SELECT IDENTITY(g.user) AS id, SUM(g.amount) AS total FROM '.ExperienceGain::class.' g WHERE g.gainedAt >= :since GROUP BY g.user ORDER BY total DESC')
            ->setParameter('since', $this->now()->modify('-24 hours'))
            ->setMaxResults($limit)
            ->getArrayResult();
        $result = [];
        foreach ($rows as $row) {
            $user = $this->em->find(User::class, $row['id']);
            if (null !== $user && $row['total'] >= 0.5) {
                $result[] = ['user' => $user, 'experience' => (float) $row['total']];
            }
        }

        return $result;
    }

    /** All planet slots of the universe (systems have 10–50 slots). */
    private function universeSlots(): int
    {
        static $slots = null;
        if (null === $slots) {
            $slots = 0;
            for ($g = 1; $g <= Universe::GALAXIES; ++$g) {
                for ($s = 1; $s <= Universe::SYSTEMS_PER_GALAXY; ++$s) {
                    $slots += Universe::planetsInSystem($g, $s);
                }
            }
        }

        return $slots;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
