<?php

namespace App\Tests\Game;

use App\Game\AttackRules;
use App\Game\ShipClass;
use App\Game\ShipType;
use PHPUnit\Framework\TestCase;

/** Pin-prick attacks are not allowed: 1 % of the fleet strength or 10 % of a ship class. */
final class AttackRulesTest extends TestCase
{
    use RendersMessages;

    public function testEveryWarshipHasAClassAndCivilShipsNone(): void
    {
        foreach (ShipType::cases() as $type) {
            self::assertSame($type->isCombatShip(), null !== $type->shipClass(), $type->label());
        }
        self::assertSame(ShipClass::Fighter, ShipType::Pfeil->shipClass());
        self::assertSame(ShipClass::Frigate, ShipType::Bastion->shipClass());
        self::assertSame(ShipClass::Cruiser, ShipType::HeavyBomber->shipClass());
        self::assertSame(ShipClass::Battleship, ShipType::Leviathan->shipClass());
    }

    public function testOnePercentOfTheWholeStrengthIsEnough(): void
    {
        // 1 Leviathan = 1.8 M resources, as much as 450 Pfeile; 5 Pfeile are 1.1 % of 1 Leviathan + 5 Pfeile + …
        $all = ['leviathan' => 1, 'pfeil' => 5];
        self::assertNull(AttackRules::rejection(['pfeil' => 5], $all), '20 000 of 1 820 000 = 1.1 %');

        $all = ['leviathan' => 2, 'pfeil' => 100];
        self::assertNotNull(AttackRules::rejection(['pfeil' => 5], $all), 'too small: 0.5 % and only 5 % of the fighters');
    }

    public function testTenPercentOfAClassIsEnoughEvenIfTheShareIsTiny(): void
    {
        $all = ['leviathan' => 100, 'pfeil' => 20];
        $reason = AttackRules::rejection(['pfeil' => 1], $all);
        self::assertStringContainsString('Zu kleiner Angriff', self::text($reason));

        self::assertNull(AttackRules::rejection(['pfeil' => 2], $all), '2 of 20 fighters = 10 %');
    }

    public function testDefensesDoNotCountAsFleetStrength(): void
    {
        self::assertSame(AttackRules::strength(['pfeil' => 10]), AttackRules::strength(['pfeil' => 10, 'laser_turret' => 5000]));
    }
}
