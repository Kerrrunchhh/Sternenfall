<?php

namespace App\Game;

use App\Entity\DeletedAccount;
use App\Entity\ExperienceGain;
use App\Entity\Fleet;
use App\Entity\GameSnapshot;
use App\Entity\Planet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;

/** Figures for the status page and the start page. */
final readonly class GameStats
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private ?CacheInterface $cache = null,
    ) {
    }

    /**
     * Ships of every type in the game – hangars and fleets, defense turrets included.
     *
     * @return array<string, int> ship type => count, in the order of ShipType
     */
    public function shipTotals(): array
    {
        $totals = array_fill_keys(array_map(static fn (ShipType $t): string => $t->value, ShipType::cases()), 0);
        $connection = $this->em->getConnection();
        foreach (['planet', 'fleet'] as $table) {
            foreach ($connection->fetchFirstColumn('SELECT ships FROM '.$table) as $json) {
                foreach (json_decode((string) $json, true) ?: [] as $type => $count) {
                    if (isset($totals[$type])) {
                        $totals[$type] += (int) $count;
                    }
                }
            }
        }

        return $totals;
    }

    /**
     * Resources lying on all planets (as of each planet's last update; fleet cargo not counted).
     *
     * @return array<string, float> resource type => amount, in the order of ResourceType
     */
    public function resourceTotals(): array
    {
        $columns = array_map(static fn (ResourceType $t): string => 'COALESCE(SUM('.$t->value.'), 0) AS '.$t->value, ResourceType::cases());
        $row = $this->em->getConnection()->fetchAssociative('SELECT '.implode(', ', $columns).' FROM planet') ?: [];

        return array_map('floatval', array_merge(array_fill_keys(array_map(static fn (ResourceType $t): string => $t->value, ResourceType::cases()), 0.0), $row));
    }

    /** Takes today's snapshot unless it exists; cheap to call often (remembered in the cache). */
    public function recordDailySnapshot(): void
    {
        $day = $this->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $record = function () use ($day): bool {
            if (null === $this->em->getRepository(GameSnapshot::class)->findOneBy(['day' => $day])) {
                $this->em->persist(new GameSnapshot($day, $this->shipTotals(), $this->resourceTotals()));
                $this->em->flush();
            }

            return true;
        };
        null === $this->cache ? $record() : $this->cache->get('game_snapshot_'.$day->format('Ymd'), static fn () => $record());
    }

    /** @return list<GameSnapshot> all daily snapshots, oldest first */
    public function snapshots(): array
    {
        return $this->em->getRepository(GameSnapshot::class)->findBy([], ['day' => 'ASC']);
    }

    /**
     * Everything the status page shows about ships and resources: current totals, the
     * change against a week ago and the daily history for the charts.
     *
     * @return array{ships: list<array{type: ShipType, count: int, week: ?int}>, resources: list<array{type: ResourceType, amount: float, week: ?float}>, history: array{days: list<string>, resources: array<string, list<float>>, ships: array{ships: list<int>, turrets: list<int>}}, since: ?\DateTimeImmutable}
     */
    public function economy(): array
    {
        $ships = $this->shipTotals();
        $resources = $this->resourceTotals();
        $snapshots = $this->snapshots();
        $weekAgo = $this->now()->modify('-7 days');
        $week = null;
        foreach ($snapshots as $snapshot) {
            if ($snapshot->getDay() <= $weekAgo) {
                $week = $snapshot; // the latest one at least a week old
            }
        }

        $history = ['days' => [], 'resources' => array_fill_keys(array_keys($resources), []), 'ships' => ['ships' => [], 'turrets' => []]];
        foreach ($snapshots as $snapshot) {
            $history['days'][] = $snapshot->getDay()->format('Y-m-d');
            foreach (array_keys($resources) as $type) {
                $history['resources'][$type][] = round((float) ($snapshot->getResources()[$type] ?? 0));
            }
            [$moving, $standing] = [0, 0];
            foreach ($snapshot->getShips() as $type => $count) {
                ShipType::tryFrom($type)?->isStationary() ? $standing += $count : $moving += $count;
            }
            $history['ships']['ships'][] = $moving;
            $history['ships']['turrets'][] = $standing;
        }

        return [
            'ships' => array_map(static fn (ShipType $t): array => ['type' => $t, 'count' => $ships[$t->value], 'week' => null === $week ? null : (int) ($week->getShips()[$t->value] ?? 0)], ShipType::cases()),
            'resources' => array_map(static fn (ResourceType $t): array => ['type' => $t, 'amount' => $resources[$t->value], 'week' => null === $week ? null : (float) ($week->getResources()[$t->value] ?? 0)], ResourceType::cases()),
            'history' => $history,
            'since' => [] === $snapshots ? null : $snapshots[0]->getDay(),
        ];
    }

    /**
     * @return array{players: int, planets: int, slots: int, colonized_slots: int, ownerless_slots: int, guild_slots: int, free_slots: int, ownerless: int, transports: int, attacks: int, signups: int, signoffs: int}
     */
    public function status(): array
    {
        $since = $this->now()->modify('-24 hours');
        $scalar = fn (string $dql, array $params = []): int => (int) $this->em->createQuery($dql)->setParameters($params)->getSingleScalarResult();

        $status = [
            'players' => $scalar('SELECT COUNT(u.id) FROM '.User::class.' u WHERE u.system = false AND u.deactivatedAt IS NULL'),
            // Planets of players only: the trade guild's central systems and ownerless planets are counted apart.
            'planets' => $scalar('SELECT COUNT(p.id) FROM '.Planet::class.' p JOIN p.owner o WHERE o.system = false'),
            'colonized_slots' => $scalar('SELECT COALESCE(SUM(p.fusionSize), 0) FROM '.Planet::class.' p JOIN p.owner o WHERE o.system = false'),
            'ownerless_slots' => $scalar('SELECT COALESCE(SUM(p.fusionSize), 0) FROM '.Planet::class.' p JOIN p.owner o WHERE o.system = true AND o.username <> :guild', ['guild' => TradeGuild::NAME]),
            'guild_slots' => $scalar('SELECT COALESCE(SUM(p.fusionSize), 0) FROM '.Planet::class.' p JOIN p.owner o WHERE o.username = :guild AND o.system = true', ['guild' => TradeGuild::NAME]),
            'ownerless' => $scalar('SELECT COUNT(p.id) FROM '.Planet::class.' p JOIN p.owner o WHERE o.system = true AND o.username <> :guild', ['guild' => TradeGuild::NAME]),
            'slots' => $this->universeSlots(),
            'transports' => $scalar('SELECT COUNT(f.id) FROM '.Fleet::class.' f WHERE f.mission IN (:missions) AND f.returning = false', ['missions' => [FleetMission::Transport, FleetMission::TransportStation, FleetMission::Trade, FleetMission::Supply, FleetMission::Pickup]]),
            'attacks' => $scalar('SELECT COUNT(f.id) FROM '.Fleet::class.' f WHERE f.mission = :attack AND f.returning = false', ['attack' => FleetMission::Attack]),
            'signups' => $scalar('SELECT COUNT(u.id) FROM '.User::class.' u WHERE u.createdAt >= :since AND u.system = false', ['since' => $since]),
            'signoffs' => $scalar('SELECT COUNT(d.id) FROM '.DeletedAccount::class.' d WHERE d.deletedAt >= :since', ['since' => $since]),
        ];
        // Planet slots nobody has colonized yet.
        $status['free_slots'] = max(0, $status['slots'] - $status['colonized_slots'] - $status['ownerless_slots'] - $status['guild_slots']);

        return $status;
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
