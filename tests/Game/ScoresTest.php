<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\Battle;
use App\Game\BattleGroup;
use App\Game\BattleResult;
use App\Game\BuildingType;
use App\Game\Fusion;
use App\Game\ResearchType;
use App\Game\Scoring;
use PHPUnit\Framework\TestCase;

/** Points from built-up planets, combat experience from battles. */
final class ScoresTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01');
    }

    public function testPointsGrowWithPlanetsAndTheirBuildings(): void
    {
        $player = new User('bauherr', $this->now);
        $home = new Planet($player, 'Heim', 1, 1, 1, $this->now);
        $empty = Scoring::points($player);

        $home->setBuildingLevel(BuildingType::Shipyard, $home->getBuildingLevel(BuildingType::Shipyard) + 3);
        self::assertSame($empty + 9, Scoring::points($player), 'shipyard: 3 points per level');

        $before = Scoring::points($player);
        $colony = new Planet($player, 'Kolonie', 1, 1, 5, $this->now);
        $colony->setBuildingLevel(BuildingType::IronMine, 10);
        self::assertGreaterThan($before, Scoring::points($player), 'more planets, more points');
    }

    public function testFusingNeitherGainsNorLosesPoints(): void
    {
        $player = new User('schweisser', $this->now);
        $top = new Planet($player, 'Oben', 1, 1, 1, $this->now);
        $bottom = new Planet($player, 'Unten', 1, 1, 2, $this->now);
        foreach ([$top, $bottom] as $planet) {
            foreach (BuildingType::cases() as $type) {
                $planet->setBuildingLevel($type, 6);
            }
        }
        $separate = Scoring::points($player);

        Fusion::merge($top, [$bottom]);
        $player->getPlanets()->removeElement($bottom);

        self::assertSame(12, $top->getBuildingLevel(BuildingType::Shipyard));
        self::assertSame($separate, Scoring::points($player));
    }

    public function testExperienceIsTheEnemyShieldDestroyedSharedByBuildTime(): void
    {
        $big = new User('gross');
        $small = new User('klein');
        $victim = new User('opfer');

        $battle = new Battle();
        // Same ship type: the player with three times the ships gets three times the share.
        $battle->add(BattleGroup::ATTACKER, $big, ['pfeil' => 300], 'a');
        $battle->add(BattleGroup::ATTACKER, $small, ['pfeil' => 100], 'b');
        $battle->add(BattleGroup::DEFENDER, $victim, ['laser_turret' => 10], 'hangar');
        $result = $battle->fight();

        $shares = [];
        foreach ($result->experience() as $entry) {
            $shares[$entry['user']->getUsername()] = $entry['experience'];
        }
        // 10 turrets × 20 shield destroyed – turrets give half the experience of spaceships.
        self::assertEqualsWithDelta(100, $shares['gross'] + $shares['klein'], 0.001);
        self::assertEqualsWithDelta(3, $shares['gross'] / $shares['klein'], 0.001);
        self::assertArrayNotHasKey('opfer', $shares, 'the turrets fell before they could fire: nothing destroyed, no experience');
    }

    public function testTurretsGiveHalfTheExperienceOfSpaceships(): void
    {
        $attacker = new User('angreifer');
        $defender = new User('verteidiger');
        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, $attacker, ['lanze' => 200], 'a');
        // Same shield on both sides of the comparison: 10 turrets (20 each) and 8 Falken (25 each) = 200 each.
        $battle->add(BattleGroup::DEFENDER, $defender, ['laser_turret' => 10, 'falke' => 8], 'hangar');
        $result = $battle->fight();

        $experience = array_sum(array_column(array_filter($result->experience(), static fn (array $e): bool => $e['user'] === $attacker), 'experience'));
        self::assertEqualsWithDelta(200 + 200 * BattleResult::TURRET_EXPERIENCE_FACTOR, $experience, 0.001, 'Falken in full, turrets half');
        self::assertSame(0.5, BattleResult::TURRET_EXPERIENCE_FACTOR);
    }

    public function testShieldResearchOfTheEnemyCounts(): void
    {
        $attacker = new User('angreifer');
        $defender = new User('verteidiger');
        $defender->setResearchLevel(ResearchType::RotationShield, 25);

        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, $attacker, ['pfeil' => 500], 'a');
        $battle->add(BattleGroup::DEFENDER, $defender, ['falke' => 5], 'hangar');
        $experience = $battle->fight()->experience();

        // Falke shield 25, doubled by shield research level 25.
        self::assertEqualsWithDelta(5 * 50, $experience[0]['experience'], 0.001);
        self::assertSame($attacker, $experience[0]['user']);
    }
}
