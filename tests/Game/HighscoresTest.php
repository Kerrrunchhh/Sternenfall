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

    public function testPlayersAndAlliancesSortByCredits(): void
    {
        foreach ([300, 100, 0, 5000] as $i => $credits) {
            $this->users[$i]->addCredits($credits);
        }
        $scores = $this->highscores();

        self::assertSame(['Dora', 'Anna', 'Bert', 'Carla'], $this->names($scores->players(Highscores::BY_CREDITS)));
        $alliances = $scores->alliances(Highscores::BY_CREDITS);
        self::assertSame('Orden', $alliances[0]['alliance']->getName());
        self::assertEqualsWithDelta($this->users[0]->getCredits() + $this->users[2]->getCredits(), $alliances[0]['credits'], 0.001);
    }

    public function testPlayersAndAlliancesSortByPlanetsWeldedOnesCountSingly(): void
    {
        // Carla gets a second planet, Dora a fusion of three.
        new \App\Entity\Planet($this->users[2], 'Zweiter', 5, 5, 5, $this->now);
        $fusion = new \App\Entity\Planet($this->users[3], 'Fusion', 6, 6, 1, $this->now);
        (new \ReflectionProperty(\App\Entity\Planet::class, 'fusionSize'))->setValue($fusion, 3);
        $scores = $this->highscores();

        $rows = $scores->players(Highscores::BY_PLANETS);
        self::assertSame(['Dora', 'Carla', 'Bert', 'Anna'], $this->names($rows), 'ties fall back to points');
        self::assertSame([4, 2, 1, 1], array_column($rows, 'planets'));
        self::assertSame(3, $scores->alliances(Highscores::BY_PLANETS)[0]['planets'], 'Orden: Anna 1 + Carla 2');
    }

    public function testResearchLeadersAreTheBestThreeOfEveryResearch(): void
    {
        foreach ([3, 7, 7, 1] as $i => $level) {
            $this->users[$i]->setResearchLevel(\App\Game\ResearchType::Espionage, $level);
        }
        $groups = $this->highscores()->researchLeaders();
        $espionage = array_values(array_filter($groups['Zusatztechnologien'], static fn (array $e): bool => \App\Game\ResearchType::Espionage === $e['research']))[0];

        // Bert and Carla both have 7: Bert has more points.
        self::assertSame(['Bert', 'Carla', 'Anna'], array_map(static fn (array $l): string => $l['user']->getUsername(), $espionage['leaders']));
        self::assertSame([7, 7, 3], array_column($espionage['leaders'], 'level'));
        self::assertSame(['Carla', 'Anna'], array_map(static fn (array $l): string => $l['user']->getUsername(), array_values(array_filter($this->highscores()->researchLeaders(LifeForm::Guardian)['Zusatztechnologien'], static fn (array $e): bool => \App\Game\ResearchType::Espionage === $e['research']))[0]['leaders']), 'life form filter');
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
