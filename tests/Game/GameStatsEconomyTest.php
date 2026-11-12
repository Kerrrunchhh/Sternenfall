<?php

namespace App\Tests\Game;

use App\Entity\GameSnapshot;
use App\Game\GameStats;
use App\Game\ShipType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Status page: ships of every type and resources on all planets, with the history of daily snapshots. */
final class GameStatsEconomyTest extends TestCase
{
    public function testTotalsWeekChangeAndHistory(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturnCallback(static fn (string $sql): array => str_contains($sql, 'FROM planet')
            ? ['{"pfeil": 10, "laser_turret": 4}', '{"pfeil": 5}']
            : ['{"pfeil": 3, "falke": 2}', '{"unknown_ship": 99}']);
        $connection->method('fetchAssociative')->willReturn(['iron' => '1000.5', 'titanium' => '200', 'food' => '0', 'water' => '50', 'hydrogen' => '7']);

        $weekAgo = new GameSnapshot(new \DateTimeImmutable('2026-11-04'), ['pfeil' => 8, 'laser_turret' => 4], ['iron' => 400.0]);
        $yesterday = new GameSnapshot(new \DateTimeImmutable('2026-11-11'), ['pfeil' => 15, 'falke' => 2, 'laser_turret' => 4], ['iron' => 900.0]);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturn([$weekAgo, $yesterday]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturn($repository);

        $stats = new GameStats($em, new MockClock('2026-11-12 10:00:00'));

        $ships = $stats->shipTotals();
        self::assertSame(18, $ships['pfeil'], 'hangars and fleets together');
        self::assertSame(2, $ships['falke']);
        self::assertSame(4, $ships['laser_turret'], 'turrets are listed too');
        self::assertArrayNotHasKey('unknown_ship', $ships);
        self::assertSame(\count(ShipType::cases()), \count($ships), 'every type, also those nobody has');

        $economy = $stats->economy();
        $pfeil = array_values(array_filter($economy['ships'], static fn (array $r): bool => ShipType::Pfeil === $r['type']))[0];
        self::assertSame(['count' => 18, 'week' => 8], ['count' => $pfeil['count'], 'week' => $pfeil['week']], 'compared with the snapshot of a week ago');
        self::assertEqualsWithDelta(1000.5, $economy['resources'][0]['amount'], 0.001);
        self::assertSame(['2026-11-04', '2026-11-11'], $economy['history']['days']);
        self::assertSame([8, 17], $economy['history']['ships']['ships'], 'spaceships per day');
        self::assertSame([4, 4], $economy['history']['ships']['turrets'], 'turrets per day');
        self::assertSame([400.0, 900.0], $economy['history']['resources']['iron']);
    }
}
