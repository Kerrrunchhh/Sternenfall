<?php

namespace App\Tests\Game;

use App\Game\BattleGroup;
use App\Game\Simulator\BattleSimulator;
use App\Game\Simulator\ForceParser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The battle simulator reads spy reports and ship lists and fights like a real attack. */
class BattleSimulatorTest extends KernelTestCase
{
    private function parser(): ForceParser
    {
        self::bootKernel();

        return static::getContainer()->get(ForceParser::class);
    }

    public function testAGermanSpyReportGivesShipsTurretsFleetsAndResearch(): void
    {
        $report = <<<'TXT'
            Planet Heimat [1:2:3] von Halden
            Planetentyp: 🔵 Blauer Planet (Rohstoffe ×1)

            Rohstoffe:
              Eisen: 12.000
            Bevölkerung: 5.450

            Gebäude:
              Werft: Stufe 4
            Forschung:
              Impulslaser: Stufe 7
              Rotationsschild: Stufe 3
              Spionagetechnik: Stufe 5

            Stationierte Schiffe und Türme:
              Pfeil: 1.240
              Großfrachter: 3
              Frachter: 6
              Lasergeschütz: 25
              Flotte von Teska:
                Pfeil: 10
                Inferno: 3
            TXT;

        $force = $this->parser()->parse($report);

        self::assertSame('Halden', $force->owner);
        self::assertSame(['pfeil' => 1250, 'large_freighter' => 3, 'freighter' => 6, 'laser_turret' => 25, 'inferno' => 3], $force->ships, 'fleets on the planet count too, "Frachter" is not taken out of "Großfrachter"');
        self::assertSame(['impulse_laser' => 7, 'rotation_shield' => 3], $force->research, 'only combat research');
    }

    public function testAnEnglishReportAndACopiedShipListWork(): void
    {
        $parser = $this->parser();

        $english = $parser->parse("Planet Home [1:2:3] of Halden\nResearch:\n  Pulse laser: level 4\nStationed ships and turrets:\n  Large freighter: 1,500\n  Laser turret: 2");
        self::assertSame(['large_freighter' => 1500, 'laser_turret' => 2], $english->ships);
        self::assertSame(['impulse_laser' => 4], $english->research);

        $list = $parser->parse("Pfeil\t40\nFalke\t12\n20 × Lanze, 5 × Schwerer Bomber");
        self::assertSame(['pfeil' => 40, 'falke' => 12, 'lanze' => 20, 'heavy_bomber' => 5], $list->ships);
        self::assertSame([], $list->research);
        self::assertNull($list->owner);
    }

    public function testResearchDecidesTheBattle(): void
    {
        $simulator = new BattleSimulator();
        $fleet = ['lanze' => 100];
        $home = ['pfeil' => 300];

        $weak = $simulator->simulate($fleet, [], $home, []);
        $strong = $simulator->simulate($fleet, ['impulse_laser' => 25, 'heavy_laser' => 25, 'electron_cannon' => 25, 'rotation_shield' => 25, 'phase_shield' => 25], $home, []);

        self::assertGreaterThan(
            $weak->survivingCount(BattleGroup::ATTACKER, \App\Game\ShipType::Lanze),
            $strong->survivingCount(BattleGroup::ATTACKER, \App\Game\ShipType::Lanze),
            'better weapons and shields keep more ships alive',
        );
        self::assertGreaterThan(0, BattleSimulator::lostValue($weak, BattleGroup::DEFENDER)->iron + BattleSimulator::lostValue($weak, BattleGroup::ATTACKER)->iron);
    }

    public function testTurretsDoNotAttack(): void
    {
        $result = (new BattleSimulator())->simulate(['laser_turret' => 50], [], ['pfeil' => 1], []);

        self::assertSame([], $result->side(BattleGroup::ATTACKER));
    }
}
