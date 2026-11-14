<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\Battle;
use App\Game\BattleGroup;
use App\Game\BattleResult;
use App\Game\ShipType;
use App\Game\TargetGroup;
use PHPUnit\Framework\TestCase;

/** Whom units shoot first: fighters hunt invasion ships and bombers, every class its own last. */
class TargetPriorityTest extends TestCase
{
    public function testFightersShootInvasionShipsBeforeAnythingElse(): void
    {
        // 10 attacking Falken = 950 damage (attack 95), triple against invaders: 2.850 of the 6.400 hit
        // points of two invasion ships (3.000 hull + 200 shield each) – the frigates are spared.
        $hits = $this->firstShot(['falke' => 10], ['invasion_ship' => 2, 'lanze' => 5, 'heavy_bomber' => 1]);

        self::assertSame([['group' => 1, 'damage' => 2850.0, 'destroyed' => 0]], $hits, 'invasion ships before bombers and frigates');
    }

    public function testWhatIsLeftAfterDestroyingAGroupGoesOnToTheNext(): void
    {
        // 70 attacking Falken = 6.650 damage: 2.133,3 of it destroy both invasion ships (6.400 hit
        // points, triple damage), the other 4.516,7 go on to the frigates (5 × 1.360): 3 Lanzen fall.
        $hits = $this->firstShot(['falke' => 70], ['invasion_ship' => 2, 'lanze' => 5]);

        self::assertSame(['group' => 1, 'damage' => 6400.0, 'destroyed' => 2], $hits[0]);
        self::assertSame(['group' => 2, 'damage' => 4516.7, 'destroyed' => 3], $hits[1]);
    }

    public function testFightersGetThroughTheShieldsOfBombersAndInvasionShips(): void
    {
        // The defenders' 40 Pfeile: 2.000 damage a round, triple against invaders = 6.000 – the two
        // invasion ships (6.400 hit points) don't survive the second round.
        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, new User('a'), ['heavy_bomber' => 6, 'invasion_ship' => 2]);
        $battle->add(BattleGroup::DEFENDER, new User('d'), ['pfeil' => 40], 'hangar');
        $result = $battle->fight();

        self::assertSame(0, $result->survivingCount(BattleGroup::ATTACKER, ShipType::InvasionShip), 'the fighters stop the invasion');
        self::assertSame(3.0, TargetGroup::damageFactor(ShipType::Pfeil, TargetGroup::Invaders));
        self::assertSame(1.0, TargetGroup::damageFactor(ShipType::Pfeil, TargetGroup::Frigates));
        self::assertSame(3.0, TargetGroup::damageFactor(ShipType::Brecher, TargetGroup::Turrets));
    }

    public function testAClassShootsTheOtherClassesBeforeItsOwn(): void
    {
        // Lanzen (frigates) aim at fighters first: the Korsaren (frigates too) are left alone.
        $result = $this->fight(['lanze' => 2], ['pfeil' => 10, 'korsar' => 3]);

        self::assertEqualsWithDelta(3 * 2620, $this->hp($result, ShipType::Korsar), 0.01);
        self::assertLessThan(10 * 410, $this->hp($result, ShipType::Pfeil));
    }

    public function testBreakersGoForTheTurretsFirstAndHitThemThreeTimesAsHard(): void
    {
        self::assertSame(TargetGroup::Turrets, TargetGroup::prioritiesOf(ShipType::Brecher)[0]);
        self::assertSame(TargetGroup::Invaders, TargetGroup::prioritiesOf(ShipType::LaserTurret)[0], 'turrets protect the planet from invaders');
        self::assertSame(TargetGroup::Bombers, TargetGroup::of(ShipType::HeavyBomber));
        self::assertSame([TargetGroup::Invaders, TargetGroup::Bombers], \array_slice(TargetGroup::prioritiesOf(ShipType::Falke), 0, 2), 'fighters: invasion ships first, then bombers');
        $bomber = TargetGroup::prioritiesOf(ShipType::HeavyBomber);
        self::assertSame(TargetGroup::Cruisers, end($bomber), 'bombers shoot like cruisers');
    }

    public function testOnlyFightingUnitsShowPriorities(): void
    {
        self::assertSame(TargetGroup::prioritiesOf(ShipType::Pfeil), ShipType::Pfeil->targetPriorities());
        self::assertNotEmpty(ShipType::LaserTurret->targetPriorities());
        self::assertSame([], ShipType::Freighter->targetPriorities());
        self::assertSame([], ShipType::InvasionShip->targetPriorities());
    }

    public function testEveryFightingUnitHasEveryTargetGroupOnceAndItsOwnClassLast(): void
    {
        foreach (ShipType::cases() as $type) {
            if (!$type->isCombatShip() && !$type->isStationary()) {
                continue;
            }
            $priorities = TargetGroup::prioritiesOf($type);
            self::assertSame(\count($priorities), \count(array_unique(array_map(static fn (TargetGroup $g): string => $g->value, $priorities))), $type->value);
            $own = TargetGroup::of($type);
            if (!$type->isStationary() && !\in_array($own, [TargetGroup::Invaders, TargetGroup::Bombers], true)) {
                self::assertSame($own, end($priorities), $type->value.': own class last');
            }
        }
    }

    /**
     * The hits of the attacker's first shot (round 1).
     *
     * @param array<string, int> $attackers
     * @param array<string, int> $defenders
     *
     * @return list<array{group: int, damage: float, destroyed: int}>
     */
    private function firstShot(array $attackers, array $defenders): array
    {
        return $this->fight($attackers, $defenders)->replay()['steps'][0]['shots'][0]['hits'];
    }

    /**
     * @param array<string, int> $attackers
     * @param array<string, int> $defenders
     */
    private function fight(array $attackers, array $defenders): BattleResult
    {
        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, new User('a'), $attackers);
        $battle->add(BattleGroup::DEFENDER, new User('d'), $defenders, 'hangar');

        return $battle->fight();
    }

    private function hp(BattleResult $result, ShipType $type): float
    {
        foreach ($result->groups as $group) {
            if ($group->type === $type) {
                return $group->hp;
            }
        }
        self::fail('no '.$type->value);
    }
}
