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

    public function testPlayersLeavingTheGameAreNotRanked(): void
    {
        $this->users[1]->requestDeletion(new \DateTimeImmutable('+48 hours'), false); // Bert deletes his account
        $this->users[3]->deactivate(new \DateTimeImmutable()); // Dora was deactivated

        self::assertSame(['Anna', 'Carla'], $this->names($this->highscores()->players(Highscores::BY_POINTS)));
        self::assertSame([1, 2], array_column($this->highscores()->players(Highscores::BY_POINTS), 'rank'), 'the ranks close up');
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

    public function testEqualPointsGoToMorePlanetsThenToWhoWasThereFirst(): void
    {
        // Three newcomers with exactly the same points (no buildings).
        $make = function (string $name, string $joined, int $planets): User {
            $user = new User($name, new \DateTimeImmutable($joined));
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, 100 + \count($this->users));
            for ($i = 0; $i < $planets; ++$i) {
                new Planet($user, 'P'.$i, 9, 9, \count($this->users) * 10 + $i, $this->now);
            }

            return $this->users[] = $user;
        };
        $this->users = [];
        $make('Zora', '2026-01-03', 1);
        $make('Yann', '2026-01-02', 1);
        $make('Xaver', '2026-01-04', 2);

        $rows = $this->highscores()->players(Highscores::BY_POINTS);

        self::assertSame(['Xaver', 'Yann', 'Zora'], $this->names($rows), 'more planets first, then the earlier player');
        self::assertSame([1, 2, 3], array_column($rows, 'rank'), 'no shared ranks when planets or start differ');
        self::assertSame(array_fill(0, 3, $rows[0]['points']), array_column($rows, 'points'));
    }

    public function testResearchPointsAddUpAllResearchLevels(): void
    {
        $this->users[3]->setResearchLevel(\App\Game\ResearchType::Espionage, 4);
        $this->users[3]->setResearchLevel(\App\Game\ResearchType::IonDrive, 3);
        $this->users[2]->setResearchLevel(\App\Game\ResearchType::Espionage, 2);
        $this->users[0]->setResearchLevel(\App\Game\ResearchType::Colonization, 1);

        self::assertSame(7, \App\Game\Scoring::researchPoints($this->users[3]));
        $rows = $this->highscores()->players(Highscores::BY_RESEARCH);
        self::assertSame(['Dora', 'Carla', 'Anna', 'Bert'], $this->names($rows));
        self::assertSame([7, 2, 1, 0], array_column($rows, 'research'));
        $orden = $this->highscores()->alliances(Highscores::BY_RESEARCH)[0];
        self::assertSame('Orden', $orden['alliance']->getName());
        self::assertSame(3, $orden['research'], 'Anna 1 + Carla 2');
    }

    public function testAProfileShowsThePlayersPlaceInEveryRanking(): void
    {
        $this->users[3]->setResearchLevel(\App\Game\ResearchType::Espionage, 4);
        $standing = $this->highscores()->standingOf($this->users[3]); // Dora

        self::assertSame(Highscores::SORTS, array_keys($standing));
        self::assertSame(4, $standing['points']['rank'], 'fewest points');
        self::assertSame(1, $standing['research']['rank']);
        self::assertSame(4.0, $standing['research']['value']);
        self::assertSame(4, $standing['research']['of']);

        $this->users[1]->deactivate(new \DateTimeImmutable()); // Bert
        self::assertNull($this->highscores()->standingOf($this->users[1]), 'not ranked: no places');
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
