<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\Fusion;
use App\Game\Resources;
use App\Game\ShipType;
use PHPUnit\Framework\TestCase;

class FusionTest extends TestCase
{
    private \DateTimeImmutable $now;
    private User $owner;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01 12:00:00');
        $this->owner = new User('schweisser');
    }

    public function testDistributeIsAsEvenAsPossible(): void
    {
        self::assertSame([3, 2, 2], Fusion::distribute(7, 3));
        self::assertSame([1, 1, 0, 0], Fusion::distribute(2, 4));
        self::assertSame([5], Fusion::distribute(5, 1));
        self::assertSame([0, 0], Fusion::distribute(0, 2));
    }

    public function testMergeAddsEverythingAndOccupiesTheSlots(): void
    {
        $top = $this->planet(5, 'Oben', iron: 3, bio: 2, ironStock: 1000, population: 100);
        $top->addShips(ShipType::Pfeil, 10);
        $middle = $this->planet(6, 'Mitte', iron: 4, bio: 1, ironStock: 500, population: 50);
        $middle->addShips(ShipType::Pfeil, 5)->addShips(ShipType::Freighter, 2);
        $bottom = $this->planet(7, 'Unten', iron: 1, bio: 0, ironStock: 250, population: 25);

        Fusion::merge($top, [$middle, $bottom]);

        self::assertSame(8, $top->getBuildingLevel(BuildingType::IronMine));
        self::assertSame(3, $top->getBuildingLevel(BuildingType::BioCell));
        self::assertSame(1750.0, $top->getResources()->iron);
        self::assertSame(175.0, $top->getPopulation());
        self::assertSame(15, $top->getShipCount(ShipType::Pfeil), 'ships of welded planets move to the top planet');
        self::assertSame(2, $top->getShipCount(ShipType::Freighter));

        self::assertSame(3, $top->getFusionSize());
        self::assertSame('1:1:5-7', $top->getCoordinates());
        self::assertTrue($top->covers(7));
        self::assertFalse($top->covers(8));
        self::assertSame([['position' => 6, 'name' => 'Mitte'], ['position' => 7, 'name' => 'Unten']], $top->getFusedPlanets());
    }

    public function testFusingAFusionKeepsAllOriginalPlanets(): void
    {
        $lower = $this->planet(6, 'B');
        Fusion::merge($lower, [$this->planet(7, 'C')]);
        $top = $this->planet(5, 'A');

        Fusion::merge($top, [$lower]);

        self::assertSame(3, $top->getFusionSize());
        self::assertSame(['B', 'C'], array_column($top->getFusedPlanets(), 'name'));
    }

    public function testOnlyThePlanetDirectlyBelowCanBeAbsorbed(): void
    {
        $this->expectException(\LogicException::class);
        Fusion::merge($this->planet(5, 'A'), [$this->planet(7, 'C')]);
    }

    public function testSplitRestoresPlanetsAndSpreadsLevelsEvenly(): void
    {
        $top = $this->planet(5, 'Oben', iron: 3, bio: 2, ironStock: 900, population: 300);
        $top->addShips(ShipType::Pfeil, 15);
        Fusion::merge($top, [$this->planet(6, 'Mitte', iron: 4, bio: 1), $this->planet(7, 'Unten', iron: 0, bio: 1)]);
        // 7 iron mine levels, 4 bio cell levels, 900 iron, 300 inhabitants over 3 planets

        $restored = Fusion::split($top, $this->now);

        self::assertCount(2, $restored);
        self::assertSame([6, 7], array_map(static fn (Planet $p) => $p->getPosition(), $restored));
        self::assertSame(['Mitte', 'Unten'], array_map(static fn (Planet $p) => $p->getName(), $restored));
        $all = [$top, ...$restored];
        self::assertSame([3, 2, 2], array_map(static fn (Planet $p) => $p->getBuildingLevel(BuildingType::IronMine), $all));
        self::assertSame([2, 1, 1], array_map(static fn (Planet $p) => $p->getBuildingLevel(BuildingType::BioCell), $all));
        foreach ($all as $planet) {
            self::assertEqualsWithDelta(300, $planet->getResources()->iron, 0.001);
            self::assertEqualsWithDelta(100, $planet->getPopulation(), 0.001);
            self::assertSame($this->owner, $planet->getOwner());
        }
        self::assertSame(15, $top->getShipCount(ShipType::Pfeil), 'ships stay on the top planet');
        self::assertSame(0, $restored[0]->getShipCount(ShipType::Pfeil));
        self::assertFalse($top->isFused());
        self::assertSame('1:1:5', $top->getCoordinates());
    }

    public function testFusionAddsUpDefensesAndBlowingItApartDestroysThem(): void
    {
        $top = $this->planet(5, 'Oben');
        $top->addShips(ShipType::LaserTurret, 10)->addShips(ShipType::Pfeil, 3);
        $lower = $this->planet(6, 'Unten');
        $lower->addShips(ShipType::LaserTurret, 5)->addShips(ShipType::PlasmaTurret, 1);

        Fusion::merge($top, [$lower]);
        self::assertSame(15, $top->getShipCount(ShipType::LaserTurret));
        self::assertSame(1, $top->getShipCount(ShipType::PlasmaTurret));

        $restored = Fusion::split($top, $this->now);
        self::assertSame(0, $top->getShipCount(ShipType::LaserTurret), 'all defenses are destroyed');
        self::assertSame(0, $top->getShipCount(ShipType::PlasmaTurret));
        self::assertSame(0, $restored[0]->getShipCount(ShipType::LaserTurret));
        self::assertSame(3, $top->getShipCount(ShipType::Pfeil), 'ships survive');
    }

    public function testBreakingOffTakesTheBottomPlanetWithItsShare(): void
    {
        // 20 welded planets: the broken-off one takes 5 % of everything.
        $top = $this->planet(1, 'P1', iron: 40, bio: 20, ironStock: 2000, population: 1000);
        $top->addShips(ShipType::LaserTurret, 50);
        $chain = [];
        for ($position = 2; $position <= 20; ++$position) {
            $chain[] = $this->planet($position, 'P'.$position);
        }
        Fusion::merge($top, $chain);

        $planet = Fusion::breakOff($top, $this->now);

        self::assertSame(20, $planet->getPosition());
        self::assertSame('P20', $planet->getName());
        self::assertSame($this->owner, $planet->getOwner());
        self::assertSame(19, $top->getFusionSize());
        self::assertSame('1:1:1-19', $top->getCoordinates());
        self::assertSame(100.0, $planet->getResources()->iron);
        self::assertSame(1900.0, $top->getResources()->iron);
        self::assertSame(50.0, $planet->getPopulation());
        self::assertSame(950.0, $top->getPopulation());
        self::assertSame(2, $planet->getBuildingLevel(BuildingType::IronMine));
        self::assertSame(38, $top->getBuildingLevel(BuildingType::IronMine));
        self::assertSame(1, $planet->getBuildingLevel(BuildingType::BioCell));
        self::assertSame(0, $planet->getShipCount(ShipType::LaserTurret), 'defenses stay with the fusion');
        self::assertSame(50, $top->getShipCount(ShipType::LaserTurret));
    }

    public function testInvasionChanceNeverReachesZeroOrHundredPercent(): void
    {
        self::assertEqualsWithDelta(0.9, \App\Game\Economy::invasionChance(1, 20_000), 1e-9, 'one ship for 20.000 inhabitants');
        self::assertEqualsWithDelta(0.45, \App\Game\Economy::invasionChance(1, 40_000), 1e-9);
        self::assertEqualsWithDelta(0.9, \App\Game\Economy::invasionChance(10, 200_000), 1e-9);
        self::assertSame(0.99, \App\Game\Economy::invasionChance(50, 1_000), 'never 100 %');
        self::assertSame(0.01, \App\Game\Economy::invasionChance(1, 100_000_000), 'never 0 %');
        self::assertSame(0.99, \App\Game\Economy::invasionChance(1, 0), 'empty planet');
    }

    public function testFleetsOfWeldedSlotsFollowTheFusion(): void
    {
        $waiting = new \App\Entity\Fleet($this->owner, 'Wache', 1, 1, 6, ['pfeil' => 4]);
        $returning = new \App\Entity\Fleet($this->owner, 'Heimkehrer', 1, 1, 5, ['pfeil' => 2]);
        $returning->depart(\App\Game\FleetMission::Transport, 2, 2, 2, $this->now, 60);
        $incoming = new \App\Entity\Fleet($this->owner, 'Lieferung', 2, 2, 2, ['freighter' => 1]);
        $incoming->depart(\App\Game\FleetMission::Transport, 1, 1, 6, $this->now, 60);
        $elsewhere = new \App\Entity\Fleet($this->owner, 'Fern', 1, 2, 6, ['pfeil' => 1]);

        foreach ([$waiting, $returning, $incoming, $elsewhere] as $fleet) {
            $fleet->followFusion(1, 1, [5, 6], 4);
        }

        self::assertSame('1:1:4', $waiting->getCoordinates());
        self::assertSame('1:1:4', $returning->getCoordinates(), 'returns to the fusion');
        self::assertSame('1:1:4', $incoming->getTargetCoordinates(), 'arrives at the fusion');
        self::assertSame('1:2:6', $elsewhere->getCoordinates(), 'other systems are untouched');
    }

    public function testFusionIsABluePlanetAndProducesHalfAsMuchAgain(): void
    {
        $economy = new \App\Game\Economy(1.0);
        // A desert slot: low food and water as a single planet.
        $slot = PlanetUpdaterTest::slotOf(\App\Game\PlanetType::Desert);
        $single = new Planet($this->owner, 'Wüste', 1, 1, $slot, $this->now);
        $single->setBuildingLevel(BuildingType::Farm, 5)->setBuildingLevel(BuildingType::IronMine, 5);
        self::assertSame(\App\Game\PlanetType::Desert, $single->getType());
        $alone = $economy->productionPerHour($single);

        $fused = new Planet($this->owner, 'Wüste', 1, 1, $slot, $this->now);
        $fused->setBuildingLevel(BuildingType::Farm, 5)->setBuildingLevel(BuildingType::IronMine, 5);
        Fusion::merge($fused, [new Planet($this->owner, 'Leer', 1, 1, $slot + 1, $this->now)]);

        self::assertSame(\App\Game\PlanetType::Blue, $fused->getType(), 'a fusion counts as a blue planet');
        $together = $economy->productionPerHour($fused);
        self::assertEqualsWithDelta(1.5 * $alone->iron, $together->iron, 0.001, 'every resource ×1.5');
        self::assertEqualsWithDelta(1.5 * 2 * $alone->food, $together->food, 0.001, 'and no desert malus any more');
        self::assertSame(2.0, $fused->getType()->growthFactor());

        Fusion::split($fused, $this->now);
        self::assertSame(\App\Game\PlanetType::Desert, $fused->getType(), 'blown apart it is its slot again');
    }

    public function testHomeworldStatusSurvivesFusion(): void
    {
        $top = $this->planet(5, 'Kolonie');
        $home = $this->planet(6, 'Heimat');
        $home->markHomeworld();

        Fusion::merge($top, [$home]);
        self::assertTrue($top->isHomeworld(), 'welding the home planet keeps the protection');

        Fusion::split($top, $this->now);
        self::assertTrue($top->isHomeworld(), 'after blowing apart, the top planet stays protected');
    }

    public function testInvadedPlanetIntegratesAfterFourWeeks(): void
    {
        $planet = $this->planet(5, 'Beute');
        self::assertFalse($planet->isIntegrating($this->now));

        $planet->markInvaded($this->now);

        self::assertTrue($planet->isIntegrating($this->now->modify('+27 days')));
        self::assertFalse($planet->isIntegrating($this->now->modify('+28 days')));
    }

    public function testSplitCostIsPerWeldedPlanet(): void
    {
        $top = $this->planet(5, 'A');
        self::assertSame(0.0, Fusion::splitCost($top)->hydrogen);

        Fusion::merge($top, [$this->planet(6, 'B'), $this->planet(7, 'C')]);
        self::assertSame(200_000.0, Fusion::splitCost($top)->hydrogen);
    }

    private function planet(int $position, string $name, int $iron = 0, int $bio = 0, float $ironStock = 0, float $population = 0): Planet
    {
        $planet = new Planet($this->owner, $name, 1, 1, $position, $this->now);
        $planet->setBuildingLevel(BuildingType::IronMine, $iron)->setBuildingLevel(BuildingType::BioCell, $bio);
        $planet->setResources(new Resources(iron: $ironStock));
        $planet->setPopulation($population);

        return $planet;
    }
}
