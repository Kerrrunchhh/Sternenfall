<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\Battle;
use App\Game\BattleGroup;
use App\Game\ResearchType;
use App\Game\ShipType;
use PHPUnit\Framework\TestCase;

class BattleTest extends TestCase
{
    public function testOverwhelmingAttackerWinsAndLosesNothing(): void
    {
        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, new User('a'), ['pfeil' => 100]);
        $battle->add(BattleGroup::DEFENDER, new User('d'), ['freighter' => 2], 'hangar');

        $result = $battle->fight();

        // 100 Pfeile fire first (5000 damage) and wipe out 2 freighters (820 hp) before they can shoot.
        self::assertTrue($result->attackerWon());
        self::assertSame(100, $result->survivingCount(BattleGroup::ATTACKER, ShipType::Pfeil));
        self::assertSame([], $result->survivors(BattleGroup::DEFENDER, 'hangar'));
    }

    public function testFiringOrderMattersInASingleRound(): void
    {
        // Pfeile (order 1) shoot before Lanzen (order 4): enough Pfeile kill the Lanzen before they fire.
        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, new User('a'), ['lanze' => 2]);
        $battle->add(BattleGroup::DEFENDER, new User('d'), ['pfeil' => 60], 'hangar');

        $result = $battle->fight();

        // 60 × 50 = 3000 damage vs 2 × (1300 + 60) = 2720 hp: both Lanzen are gone before shooting.
        self::assertSame(0, $result->survivingCount(BattleGroup::ATTACKER, ShipType::Lanze));
        self::assertSame(60, $result->survivingCount(BattleGroup::DEFENDER, ShipType::Pfeil));
        self::assertFalse($result->attackerWon());
    }

    public function testOneRoundCanEndUndecided(): void
    {
        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, new User('a'), ['pfeil' => 10]);
        $battle->add(BattleGroup::DEFENDER, new User('d'), ['pfeil' => 10], 'hangar');

        $result = $battle->fight();

        // Same order: both fire simultaneously, 500 damage each vs 4100 hp: few losses, no winner.
        self::assertFalse($result->attackerWon());
        self::assertGreaterThan(0, $result->survivingCount(BattleGroup::ATTACKER, ShipType::Pfeil));
        self::assertGreaterThan(0, $result->survivingCount(BattleGroup::DEFENDER, ShipType::Pfeil));
    }

    public function testWeaponResearchIncreasesDamage(): void
    {
        $weak = new User('weak');
        $strong = new User('strong');
        $strong->setResearchLevel(ResearchType::ImpulseLaser, 10); // +100 %

        $losses = [];
        foreach ([$weak, $strong] as $attacker) {
            $battle = new Battle();
            $battle->add(BattleGroup::ATTACKER, $attacker, ['pfeil' => 20]);
            $battle->add(BattleGroup::DEFENDER, new User('d'), ['freighter' => 10], 'hangar');
            $losses[] = 10 - $battle->fight()->survivingCount(BattleGroup::DEFENDER, ShipType::Freighter);
        }

        self::assertGreaterThan($losses[0], $losses[1]);
    }

    public function testBrecherDealsTripleDamageToTurrets(): void
    {
        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, new User('a'), ['brecher' => 1]);
        // 9000 attack × 3 = 27000 vs 3 Tachyon turrets à 8800 hp (26400): all destroyed.
        $battle->add(BattleGroup::DEFENDER, new User('d'), ['tachyon_turret' => 3], 'hangar');

        $result = $battle->fight();

        self::assertSame(0, $result->survivingCount(BattleGroup::DEFENDER, ShipType::TachyonTurret));
    }

    public function testJointAttackersFightTogetherAndEachGetsExperience(): void
    {
        $a = new User('a');
        $b = new User('b');
        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, $a, ['pfeil' => 30], 'attacker');
        $battle->add(BattleGroup::ATTACKER, $b, ['pfeil' => 30], 'joiner:1');
        $battle->add(BattleGroup::DEFENDER, new User('d'), ['falke' => 10], 'hangar');

        $result = $battle->fight();

        // 60 Pfeile together deal 3 000 damage: about 3 of the 10 Falken (775 hp each) fall.
        // Both fleets come out separately and both players share the experience.
        self::assertSame(7, $result->survivingCount(BattleGroup::DEFENDER, ShipType::Falke));
        self::assertNotSame([], $result->survivors(BattleGroup::ATTACKER, 'attacker'));
        self::assertNotSame([], $result->survivors(BattleGroup::ATTACKER, 'joiner:1'));
        $users = array_map(static fn (array $e): string => $e['user']->getUsername(), $result->experience());
        self::assertContains('a', $users);
        self::assertContains('b', $users);
    }

    public function testReportIsWrittenFromTheReadersPointOfView(): void
    {
        $now = new \DateTimeImmutable();
        $orden = new \App\Entity\Alliance('Orden', 'ORD', \App\Game\LifeForm::Settler, $now);
        (new \ReflectionProperty(\App\Entity\Alliance::class, 'id'))->setValue($orden, 1);
        [$me, $mate, $enemy] = [new User('ich'), new User('kamerad'), new User('feind')];
        foreach ([[$me, 1], [$mate, 2], [$enemy, 3]] as [$user, $id]) {
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        }
        $me->joinAlliance($orden, \App\Game\AllianceRank::Member);
        $mate->joinAlliance($orden, \App\Game\AllianceRank::Member);

        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, $enemy, ['pfeil' => 40], 'attacker');
        $battle->add(BattleGroup::DEFENDER, $mate, ['pfeil' => 10], 'a');
        $battle->add(BattleGroup::DEFENDER, $me, ['falke' => 5], 'hangar');
        $result = $battle->fight();

        $mine = $result->report($me);
        self::assertMatchesRegularExpression('/Verteidiger:\n  Du \(ich\):\n    Falke: 5 → \d/', $mine, 'own units first, marked "Du"');
        self::assertStringContainsString('kamerad [ORD] · Allianz:', $mine);
        self::assertMatchesRegularExpression('/Pfeil: 10 → \d+ \(−\d+\)/', $mine, 'how many are left and lost');

        $neutral = $result->report();
        self::assertStringNotContainsString('Du (', $neutral);
        self::assertStringNotContainsString('Allianz:', $neutral);
    }

    public function testRaidWithoutBomberSparesTransports(): void
    {
        $battle = new Battle();
        $battle->spareTransportsWithoutBomber();
        $battle->add(BattleGroup::ATTACKER, new User('pirate'), ['inferno' => 10]);
        $battle->add(BattleGroup::DEFENDER, new User('trader'), ['freighter' => 5, 'pfeil' => 3], '7');

        $result = $battle->fight();

        self::assertTrue($result->transportsSpared);
        self::assertSame(5, $result->survivingCount(BattleGroup::DEFENDER, ShipType::Freighter));
        self::assertSame(0, $result->survivingCount(BattleGroup::DEFENDER, ShipType::Pfeil), 'escorts can be destroyed');
        self::assertTrue($result->attackerWon(), 'spared transports do not count as defenders');
    }

    public function testEmptyTradersFlyingBackCanBeRaided(): void
    {
        $owner = new User('haendler');
        $trader = new \App\Entity\Fleet($owner, 'Karawane', 1, 1, 1, ['large_freighter' => 3]);
        $trader->depart(\App\Game\FleetMission::Transport, 1, 2, 3, new \DateTimeImmutable(), 60);
        self::assertFalse(\App\Game\Radar::isRaidable($trader), 'empty on the way out: nothing to raid');

        $trader->startReturn();
        self::assertTrue(\App\Game\Radar::isRaidable($trader), 'empty on the way back from a transport');

        $attacker = new \App\Entity\Fleet($owner, 'Angriff', 1, 1, 1, ['pfeil' => 5]);
        $attacker->depart(\App\Game\FleetMission::Attack, 1, 2, 3, new \DateTimeImmutable(), 60);
        $attacker->startReturn();
        self::assertFalse(\App\Game\Radar::isRaidable($attacker), 'returning attackers without cargo are no raid target');
    }

    public function testRaidWithBomberHitsTransports(): void
    {
        $battle = new Battle();
        $battle->spareTransportsWithoutBomber();
        $battle->add(BattleGroup::ATTACKER, new User('pirate'), ['inferno' => 10, 'heavy_bomber' => 1]);
        $battle->add(BattleGroup::DEFENDER, new User('trader'), ['freighter' => 5], '7');

        $result = $battle->fight();

        self::assertFalse($result->transportsSpared);
        self::assertSame(0, $result->survivingCount(BattleGroup::DEFENDER, ShipType::Freighter));
    }

    public function testDestroyedValueFeedsTheDebrisField(): void
    {
        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, new User('a'), ['pfeil' => 100]);
        $battle->add(BattleGroup::DEFENDER, new User('d'), ['freighter' => 2], 'hangar');

        $destroyed = $battle->fight()->destroyedValue();

        self::assertSame(4000.0, $destroyed->iron);
        self::assertSame(2000.0, $destroyed->titanium);
    }

    public function testRadarRangeAndVisibility(): void
    {
        self::assertNull(\App\Game\Radar::range(0));
        self::assertSame(0, \App\Game\Radar::range(1), 'level 1 covers the own system');
        self::assertSame(1, \App\Game\Radar::range(5));
        self::assertSame(2, \App\Game\Radar::range(12));

        $owner = new User('o');
        $idle = new \App\Entity\Fleet($owner, 'Wache', 1, 1, 1, ['pfeil' => 5]);
        self::assertFalse(\App\Game\Radar::emits($idle), 'waiting fleets are invisible');

        $spy = new \App\Entity\Fleet($owner, 'Späher', 1, 1, 1, ['scout' => 1]);
        $spy->depart(\App\Game\FleetMission::Spy, 1, 2, 3, new \DateTimeImmutable(), 60);
        self::assertFalse(\App\Game\Radar::emits($spy));

        $attack = new \App\Entity\Fleet($owner, 'Angriff', 1, 1, 1, ['pfeil' => 5]);
        $attack->depart(\App\Game\FleetMission::Attack, 1, 2, 3, new \DateTimeImmutable(), 60);
        self::assertTrue(\App\Game\Radar::emits($attack));
        self::assertSame([1, 2, 3], \App\Game\Radar::nextStop($attack));

        $transport = new \App\Entity\Fleet($owner, 'Karawane', 1, 1, 1, ['freighter' => 1], new \App\Game\Resources(iron: 100));
        $transport->depart(\App\Game\FleetMission::Transport, 1, 2, 3, new \DateTimeImmutable(), 60);
        self::assertTrue(\App\Game\Radar::emits($transport));

        // Unloaded at the target, the trade fleet flies back empty – and is still seen.
        $transport->setCargo(new \App\Game\Resources());
        $transport->startReturn();
        self::assertTrue(\App\Game\Radar::emits($transport), 'returning from a transport');

        $attack->startReturn();
        self::assertFalse(\App\Game\Radar::emits($attack), 'returning empty attackers stay hidden');

        $pickup = new \App\Entity\Fleet($owner, 'Abholer', 1, 1, 1, ['freighter' => 2]);
        $pickup->depart(\App\Game\FleetMission::Pickup, 1, 2, 3, new \DateTimeImmutable(), 60);
        self::assertTrue(\App\Game\Radar::emits($pickup), 'pickups fly openly, even empty on the way out');
        self::assertTrue(\App\Game\Radar::isRaidable($pickup), 'and can be raided on the way out');
    }
}
