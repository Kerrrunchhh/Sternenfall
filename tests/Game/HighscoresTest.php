<?php

namespace App\Tests\Game;

use App\Entity\Alliance;
use App\Entity\Planet;
use App\Entity\User;
use App\Game\AllianceRank;
use App\Game\BuildingType;
use App\Game\Highscores;
use App\Game\LifeForm;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class HighscoresTest extends TestCase
{
    private \DateTimeImmutable $now;
    /** @var list<User> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01');
        $orden = new Alliance('Orden', 'ORD', LifeForm::Guardian, $this->now);
        (new \ReflectionProperty(Alliance::class, 'id'))->setValue($orden, 1);
        $bande = new Alliance('Bande', 'BND', LifeForm::Pirate, $this->now);
        (new \ReflectionProperty(Alliance::class, 'id'))->setValue($bande, 2);

        // name, life form, mine level, experience, alliance
        foreach ([['Anna', LifeForm::Guardian, 10, 50, $orden], ['Bert', LifeForm::Pirate, 15, 0, $bande], ['Carla', LifeForm::Guardian, 5, 900, $orden], ['Dora', LifeForm::Pirate, 1, 10, null]] as $i => [$name, $lf, $mine, $xp, $alliance]) {
            $user = new User($name, $this->now);
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, $i + 1);
            $user->changeLifeForm($lf, $this->now);
            (new Planet($user, 'P', 1, 1, $i + 1, $this->now))->setBuildingLevel(BuildingType::IronMine, $mine);
            $user->addCombatExperience($xp);
            if ($alliance) {
                $user->joinAlliance($alliance, AllianceRank::Member);
            }
            $this->users[] = $user;
        }
    }

    public function testPlayersSortByPointsOrExperienceAndFilterByLifeForm(): void
    {
        $scores = $this->highscores();

        self::assertSame(['Bert', 'Anna', 'Carla', 'Dora'], $this->names($scores->players(Highscores::BY_POINTS)));
        self::assertSame(['Carla', 'Anna', 'Dora', 'Bert'], $this->names($scores->players(Highscores::BY_EXPERIENCE)));
        self::assertSame(['Anna', 'Carla'], $this->names($scores->players(Highscores::BY_POINTS, LifeForm::Guardian)));
        self::assertSame([1, 2], array_column($scores->players(Highscores::BY_POINTS, LifeForm::Guardian), 'rank'));
    }

    public function testAlliancesAddUpTheirMembers(): void
    {
        $rows = $this->highscores()->alliances(Highscores::BY_EXPERIENCE);

        self::assertSame('Orden', $rows[0]['alliance']->getName());
        self::assertSame(950.0, $rows[0]['experience']);
        self::assertSame(2, $rows[0]['members']);
        self::assertCount(1, $this->highscores()->alliances(Highscores::BY_POINTS, LifeForm::Pirate), 'only pirate alliances');
    }

    private function highscores(): Highscores
    {
        $query = $this->createStub(Query::class);
        $query->method('getResult')->willReturn($this->users);
        $qb = $this->createStub(QueryBuilder::class);
        foreach (['select', 'from', 'leftJoin', 'where'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        return new Highscores($em);
    }

    /** @param list<array{user: User}> $rows @return list<string> */
    private function names(array $rows): array
    {
        return array_map(static fn (array $r): string => $r['user']->getUsername(), $rows);
    }
}
