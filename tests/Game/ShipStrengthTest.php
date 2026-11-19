<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\ResearchType;
use App\Game\ShipStats;
use App\Game\ShipType;
use App\Twig\GameExtension;
use PHPUnit\Framework\TestCase;

/** The strength box below the ship pickers adds up these values per ship (research included). */
final class ShipStrengthTest extends TestCase
{
    public function testFightingValuesOfOneShipIncludeResearch(): void
    {
        $player = new User('admiral', new \DateTimeImmutable('2026-11-19'));
        $base = GameExtension::shipStrength(ShipType::Pfeil, $player);
        self::assertSame(['attack' => 40, 'defense' => 60, 'shield' => 10], $base, 'Pfeil without research');

        $player->setResearchLevel(ResearchType::ImpulseLaser, 5);
        $researched = GameExtension::shipStrength(ShipType::Pfeil, $player);
        self::assertSame((int) round(ShipStats::attack(ShipType::Pfeil, $player)), $researched['attack']);
        self::assertGreaterThan($base['attack'], $researched['attack'], 'weapon research makes it stronger');
        self::assertSame(0, GameExtension::shipStrength(ShipType::Freighter, $player)['shield'] - (int) round(ShipStats::shield(ShipType::Freighter, $player)));
    }
}
