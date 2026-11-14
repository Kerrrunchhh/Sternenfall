<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\BattleGroup;
use App\Game\BattleResult;
use App\Game\HeroPoints;
use App\Game\LifeForm;
use App\Game\ShipType;
use PHPUnit\Framework\TestCase;

class HeroPointsTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-14 12:00:00');
    }

    public function testEverybodyStartsAsAHeroAndTurnsCriminalBelowZero(): void
    {
        $player = $this->player('Pilotin');
        self::assertSame(1000.0, $player->getHeroPoints());
        self::assertFalse($player->isCriminal());

        $player->addHeroPoints(-1000);
        self::assertFalse($player->isCriminal(), 'exactly 0 is not yet criminal');
        $player->addHeroPoints(-0.5);
        self::assertTrue($player->isCriminal());
    }

    public function testWithoutNewCrimesHeroPointsComeBackTenADayUpToTheStart(): void
    {
        $player = $this->player('Bomberin');
        $player->addHeroPoints(-1050, $this->now); // -50: criminal
        self::assertTrue($player->isCriminal($this->now));

        self::assertEqualsWithDelta(-45, $player->getHeroPoints($this->now->modify('+12 hours')), 0.001);
        self::assertTrue($player->isCriminal($this->now->modify('+4 days')));
        self::assertFalse($player->isCriminal($this->now->modify('+5 days +1 minute')), 'after 5 quiet days no longer criminal');
        self::assertSame(1000.0, $player->getHeroPoints($this->now->modify('+400 days')), 'never above the start');

        // A new crime restarts the recovery; what came back so far stays.
        $player->addHeroPoints(-10, $this->now->modify('+2 days')); // -50 + 20 - 10 = -40
        self::assertEqualsWithDelta(-40, $player->getHeroPoints($this->now->modify('+2 days')), 0.001);
        self::assertEqualsWithDelta(-30, $player->getHeroPoints($this->now->modify('+3 days')), 0.001);

        // Hero points add to what recovered, and the recovery goes on from there.
        $player->addHeroPoints(25, $this->now->modify('+3 days')); // -30 + 25 = -5
        self::assertEqualsWithDelta(5, $player->getHeroPoints($this->now->modify('+4 days')), 0.001);
    }

    public function testHeroPointsAboveTheStartDoNotRecoverOrShrink(): void
    {
        $player = $this->player('Heldin');
        $player->addHeroPoints(200, $this->now);
        self::assertSame(1200.0, $player->getHeroPoints($this->now->modify('+30 days')));
    }

    public function testDestroyedCrewsOfInnocentsCostPointsOfPiratesAndCriminalsEarnThem(): void
    {
        $attacker = $this->player('Angreifer');
        $settler = $this->player('Siedlerin');
        $pirate = $this->player('Pirat', LifeForm::Pirate);
        $criminal = $this->player('Schurke');
        $criminal->addHeroPoints(-2000);

        // Each defender loses 2 leviathans = 1000 crew = 100 points.
        $result = $this->battle($attacker, [$settler, $pirate, $criminal], ShipType::Leviathan, 2);
        $earned = HeroPoints::forBattle($result);

        self::assertCount(1, $earned, 'only the attacking side earns points');
        self::assertEqualsWithDelta(-100 + 100 + 100, array_values($earned)[0]['points'], 0.001);
    }

    public function testOneBattleCostsAtMostAHundredCrimePoints(): void
    {
        $attacker = $this->player('Angreifer');
        $victim = $this->player('Opfer');
        // 20 leviathans = 10.000 crew, and 50.000 bombed inhabitants: would be 6000 points.
        $result = $this->battle($attacker, [$victim], ShipType::Leviathan, 20);

        self::assertSame(-100.0, array_values(HeroPoints::forBattle($result, 50000, $victim))[0]['points']);
        $pirate = $this->player('Pirat', LifeForm::Pirate);
        self::assertEqualsWithDelta(1000, array_values(HeroPoints::forBattle($this->battle($attacker, [$pirate], ShipType::Leviathan, 20)))[0]['points'], 0.001, 'hero points have no limit');
    }

    public function testInterceptingAnAttackNeverCostsPoints(): void
    {
        $guardian = $this->player('Wächterin', LifeForm::Guardian);
        $settler = $this->player('Siedlerin');
        $pirate = $this->player('Pirat', LifeForm::Pirate);

        self::assertSame([], HeroPoints::forBattle($this->battle($guardian, [$settler], ShipType::Leviathan, 1), defensive: true));
        self::assertEqualsWithDelta(50, array_values(HeroPoints::forBattle($this->battle($guardian, [$pirate], ShipType::Leviathan, 1), defensive: true))[0]['points'], 0.001);
    }

    public function testBombedCiviliansCountForThePlayersWithSurvivingBombers(): void
    {
        $bomberA = $this->player('Bomberin');
        $bomberB = $this->player('Begleiter');
        $victim = $this->player('Opfer');
        $a = new BattleGroup(BattleGroup::ATTACKER, $bomberA, ShipType::HeavyBomber, 3);
        $b = new BattleGroup(BattleGroup::ATTACKER, $bomberB, ShipType::HeavyBomber, 1);
        $result = new BattleResult([$a, $b, new BattleGroup(BattleGroup::DEFENDER, $victim, ShipType::Scout, 0)], false);

        $earned = array_values(HeroPoints::forBattle($result, 400, $victim));

        self::assertEqualsWithDelta(-30, $earned[0]['points'], 0.001, '3 of 4 bombers: 300 of 400 dead');
        self::assertEqualsWithDelta(-10, $earned[1]['points'], 0.001);
        self::assertSame([], HeroPoints::forBattle($result, 400, (static function (): User {
            $nobody = new User('Herrenlos');
            $nobody->markSystem();

            return $nobody;
        })()), 'ownerless planets: nobody to judge');
    }

    public function testApplyBooksThePointsAndTellsWhoTurnedCriminal(): void
    {
        $player = $this->player('Pilotin');
        $player->addHeroPoints(-990);

        $lines = HeroPoints::apply([1 => ['user' => $player, 'points' => -25.0]]);

        self::assertSame(-15.0, $player->getHeroPoints());
        $text = implode("\n", array_map(static fn ($l): string => \is_string($l) ? $l : $l->trans(new \Symfony\Component\Translation\IdentityTranslator()), $lines));
        self::assertStringContainsString('Pilotin −25 (jetzt -15)', $text);
        self::assertStringContainsString('🚨 Pilotin ist jetzt kriminell.', $text);
    }

    public function testTradersAndGuardiansSpareInnocentSettlersAndTraders(): void
    {
        $trader = $this->player('Händlerin', LifeForm::Trader);
        $guardian = $this->player('Wächter', LifeForm::Guardian);
        $settler = $this->player('Siedler');
        $otherTrader = $this->player('Krämer', LifeForm::Trader);
        $otherGuardian = $this->player('Ritter', LifeForm::Guardian);
        $pirate = $this->player('Pirat', LifeForm::Pirate);

        self::assertNotNull(HeroPoints::attackRefusal($trader, $settler, false));
        self::assertNotNull(HeroPoints::attackRefusal($guardian, $otherTrader, false));
        self::assertNull(HeroPoints::attackRefusal($guardian, $otherGuardian, false), 'guardians are no protected life form');
        self::assertNull(HeroPoints::attackRefusal($trader, $pirate, false));
        self::assertNull(HeroPoints::attackRefusal($trader, $settler, true), 'in a war everything goes');
        self::assertNull(HeroPoints::attackRefusal($settler, $otherTrader, false), 'settlers attack whom they like');

        $settler->addHeroPoints(-2000);
        self::assertNull(HeroPoints::attackRefusal($trader, $settler, false), 'criminals may be attacked by anyone');
        $guardian->addHeroPoints(-2000);
        self::assertNull(HeroPoints::attackRefusal($guardian, $otherTrader, false), 'a criminal attacks anyone');
    }

    public function testCriminalGuardiansLoseTheirDoubleSpeed(): void
    {
        $guardian = $this->player('Wächter', LifeForm::Guardian);
        $pirate = $this->player('Pirat', LifeForm::Pirate);
        self::assertSame(2.0, $guardian->speedFactor());

        $guardian->addHeroPoints(-2000);
        $pirate->addHeroPoints(-2000);

        self::assertSame(1.0, $guardian->speedFactor());
        self::assertSame(1.5, $pirate->speedFactor(), 'only guardians lose their speed');
    }

    private function player(string $name, LifeForm $lifeForm = LifeForm::Settler): User
    {
        $user = new User($name, $this->now);
        $user->changeLifeForm($lifeForm, $this->now);

        return $user;
    }

    /** @param list<User> $defenders each loses $count units of $type */
    private function battle(User $attacker, array $defenders, ShipType $type, int $count): BattleResult
    {
        $groups = [new BattleGroup(BattleGroup::ATTACKER, $attacker, ShipType::Leviathan, 10)];
        foreach ($defenders as $defender) {
            $group = new BattleGroup(BattleGroup::DEFENDER, $defender, $type, $count);
            $group->hp = 0;
            $groups[] = $group;
        }

        return new BattleResult($groups, false);
    }
}
