<?php

namespace App\Tests\Game;

use App\Entity\HighscoreSnapshot;
use App\Entity\User;
use App\Game\Highscores;
use App\Game\LifeForm;
use App\Game\RankHistory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** The highscores show how many places a player won or lost in the last 24 hours. */
final class RankHistoryTest extends TestCase
{
    public function testChangesComeFromTheSnapshotOf24HoursAgo(): void
    {
        $anna = $this->user(1, 'Anna');
        $bert = $this->user(2, 'Bert');
        $carla = $this->user(3, 'Carla');
        $neu = $this->user(5, 'Neu');
        // Yesterday: Anna 1st, Dora (gone since) 2nd, Bert 3rd, Carla 4th. Neu wasn't there.
        $history = $this->history(new HighscoreSnapshot(new \DateTimeImmutable('2026-01-01 11:00'), [
            1 => $this->values('Anna', 500),
            4 => $this->values('Dora', 400),
            2 => $this->values('Bert', 300),
            3 => $this->values('Carla', 200, LifeForm::Pirate),
        ]));
        // Today: Bert 1st, Anna 2nd, Neu 3rd, Carla 4th.
        $rows = [['rank' => 1, 'user' => $bert], ['rank' => 2, 'user' => $anna], ['rank' => 3, 'user' => $neu], ['rank' => 4, 'user' => $carla]];

        $changed = $history->withChanges($rows, Highscores::BY_POINTS);

        self::assertSame([2, -1, null, 0], array_column($changed, 'change'), 'Bert up 2, Anna down 1, Neu new, Carla stays');
        self::assertSame([1 => 1, 4 => 2, 2 => 3], $history->placesThen(Highscores::BY_POINTS, LifeForm::Guardian), 'life form filter: Carla the pirate is left out');
    }

    public function testWithoutASnapshotThereAreNoArrows(): void
    {
        $rows = [['rank' => 1, 'user' => $this->user(1, 'Anna')]];

        self::assertSame([null], array_column($this->history(null)->withChanges($rows, Highscores::BY_POINTS), 'change'));
    }

    private function history(?HighscoreSnapshot $snapshot): RankHistory
    {
        $query = $this->createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn($snapshot);
        $qb = $this->createStub(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'setParameter', 'orderBy', 'setMaxResults'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        return new RankHistory($em, new Highscores($em), new LockFactory(new InMemoryStore()), new MockClock('2026-01-02 12:00'));
    }

    /** @return array{name: string, lifeForm: string, since: int, points: int, research: int, experience: float, credits: float, planets: int} */
    private function values(string $name, int $points, LifeForm $lifeForm = LifeForm::Guardian): array
    {
        return ['name' => $name, 'lifeForm' => $lifeForm->value, 'since' => 0, 'points' => $points, 'research' => 0, 'experience' => 0.0, 'credits' => 0.0, 'planets' => 1];
    }

    private function user(int $id, string $name): User
    {
        $user = new User($name, new \DateTimeImmutable('2026-01-01'));
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
