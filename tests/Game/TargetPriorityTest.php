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
        // 10 Falken = 1.200 damage; one invasion ship has 7.200 hit points.
        $result = $this->fight(['falke' => 10], ['invasion_ship' => 1, 'lanze' => 5]);

        self::assertEqualsWithDelta(7200 - 1200, $this->hp($result, ShipType::InvasionShip), 0.01);
        self::assertEqualsWithDelta(5 * 1360, $this->hp($result, ShipType::Lanze), 0.01, 'the frigates are spared');
    }

    public function testWhatIsLeftAfterDestroyingAGroupGoesOnToTheNext(): void
    {
        // 70 Falken = 8.400 damage: 7.200 destroy the invasion ship, 1.200 go on to the frigates.
        $result = $this->fight(['falke' => 70], ['invasion_ship' => 1, 'lanze' => 5]);

        self::assertSame(0.0, $this->hp($result, ShipType::InvasionShip));
        self::assertEqualsWithDelta(5 * 1360 - 1200, $this->hp($result, ShipType::Lanze), 0.01);
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
        self::assertSame(TargetGroup::Raiders, TargetGroup::prioritiesOf(ShipType::LaserTurret)[0], 'turrets protect the planet from invaders');
        self::assertSame(TargetGroup::Raiders, TargetGroup::of(ShipType::HeavyBomber));
        self::assertSame(TargetGroup::Cruisers, TargetGroup::prioritiesOf(ShipType::HeavyBomber)[6], 'bombers shoot like cruisers');
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
            if (!$type->isStationary() && TargetGroup::Raiders !== $own) {
                self::assertSame($own, end($priorities), $type->value.': own class last');
            }
        }
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
