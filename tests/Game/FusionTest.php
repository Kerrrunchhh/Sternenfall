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
