<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\Economy;
use App\Game\LifeForm;
use App\Game\ResearchType;
use App\Game\ShipStats;
use App\Game\ShipType;
use PHPUnit\Framework\TestCase;

final class ShipStatsTest extends TestCase
{
    private ShipStats $stats;

    protected function setUp(): void
    {
        $this->stats = new ShipStats(new Economy(1.0));
    }

    public function testResearchRaisesAttackShieldAndSpeedWithoutLimit(): void
    {
        $player = new User('forscher');
        $spec = ShipType::Pfeil->spec();
        $base = $this->stats->describe(ShipType::Pfeil, $player);
        self::assertSame($spec->attack, $base['attack']['value']);

        $player->setResearchLevel(ShipType::Pfeil->weaponResearch(), 25);
        $player->setResearchLevel($spec->drive, 3);
        $stats = $this->stats->describe(ShipType::Pfeil, $player);

        self::assertSame(2 * $spec->attack, $stats['attack']['value'], 'level 25 doubles the attack');
        self::assertSame($spec->attack, $stats['attack']['base']);
        self::assertStringContainsString('Stufe 25: Angriff +100 %', $stats['attack']['notes'][0]);
        self::assertGreaterThan($base['speed']['value'], $stats['speed']['value']);
        self::assertSame(ShipStats::attack(ShipType::Pfeil, $player), 2.0 * $spec->attack, 'battles use the same value');

        $player->setResearchLevel(ShipType::Pfeil->weaponResearch(), 100);
        self::assertSame(5 * $spec->attack, $this->stats->describe(ShipType::Pfeil, $player)['attack']['value'], 'no limit: level 100 = ×5');
    }

    public function testDefensesHaveNoSpeedAndScoutsFlyOnEspionage(): void
    {
        $player = new User('wächter');
        $player->changeLifeForm(LifeForm::Guardian, new \DateTimeImmutable());
        $player->setResearchLevel(ResearchType::Espionage, 5);

        self::assertArrayNotHasKey('speed', $this->stats->describe(ShipType::LaserTurret, $player));
        $notes = implode(' ', $this->stats->describe(ShipType::Scout, $player)['speed']['notes']);
        self::assertStringContainsString('Spionagetechnik Stufe 5', $notes);
        self::assertStringContainsString('Lebensform', $notes);
    }
}
