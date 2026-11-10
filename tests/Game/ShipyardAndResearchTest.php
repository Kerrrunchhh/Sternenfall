<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\ShipyardOrder;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\Economy;
use App\Game\PlanetUpdater;
use App\Game\Requirement;
use App\Game\ResearchType;
use App\Game\ShipType;
use App\Game\Universe;
use PHPUnit\Framework\TestCase;

class ShipyardAndResearchTest extends TestCase
{
    use RendersMessages;

    private Economy $economy;
    private PlanetUpdater $updater;
    private \DateTimeImmutable $start;

    protected function setUp(): void
    {
        $this->economy = new Economy(1.0);
        $this->updater = new PlanetUpdater($this->economy);
        $this->start = new \DateTimeImmutable('2026-01-01 12:00:00');
    }

    public function testShipsAreDeliveredOneByOne(): void
    {
        $planet = $this->planet();
        $planet->addShipyardOrder(new ShipyardOrder($planet, ShipType::Pfeil, 5, 600, $this->start));

        $this->updater->update($planet, $this->start->modify('+25 minutes'));

        self::assertSame(2, $planet->getShipCount(ShipType::Pfeil));
        $order = $planet->getShipyardOrders()->first();
        self::assertSame(3, $order->getRemaining());
        self::assertEquals($this->start->modify('+30 minutes'), $order->getNextShipAt());
    }

    public function testNextOrderStartsWhenPreviousFinishes(): void
    {
        $planet = $this->planet();
        $planet->addShipyardOrder(new ShipyardOrder($planet, ShipType::Pfeil, 2, 600, $this->start));
        $planet->addShipyardOrder(new ShipyardOrder($planet, ShipType::Freighter, 3, 300, null));

        // Pfeile done after 20 min, then freighters every 5 min: 2 more after 30 min total.
        $this->updater->update($planet, $this->start->modify('+30 minutes'));

        self::assertSame(2, $planet->getShipCount(ShipType::Pfeil));
        self::assertSame(2, $planet->getShipCount(ShipType::Freighter));
        self::assertCount(1, $planet->getShipyardOrders());

        $this->updater->update($planet, $this->start->modify('+2 hours'));
        self::assertSame(3, $planet->getShipCount(ShipType::Freighter));
        self::assertCount(0, $planet->getShipyardOrders());
    }

    public function testResearchCompletes(): void
    {
        $player = new User('forscher');
        $player->startResearch(ResearchType::IonDrive, 1, $this->start->modify('+1 hour'));

        $this->updater->completeResearch($player, $this->start->modify('+59 minutes'));
        self::assertTrue($player->isResearching());

        $this->updater->completeResearch($player, $this->start->modify('+1 hour'));
        self::assertFalse($player->isResearching());
        self::assertSame(1, $player->getResearchLevel(ResearchType::IonDrive));
    }

    public function testRequirementsCheckBuildingsAndResearch(): void
    {
        $planet = $this->planet();
        $planet->setBuildingLevel(BuildingType::Shipyard, 1);

        self::assertSame(
            ['Ionenantrieb Stufe 1', 'Impulslaser Stufe 1'],
            array_map(self::text(...), $this->economy->missingRequirements(ShipType::Pfeil->spec()->requirements, $planet)),
        );

        $planet->getOwner()->setResearchLevel(ResearchType::IonDrive, 1)->setResearchLevel(ResearchType::ImpulseLaser, 1);
        self::assertSame([], $this->economy->missingRequirements(ShipType::Pfeil->spec()->requirements, $planet));
    }

    public function testDriveResearchIncreasesSpeed(): void
    {
        $player = new User('pilot');
        self::assertSame(12000, $this->economy->shipSpeed(ShipType::Pfeil, $player));

        $player->setResearchLevel(ResearchType::IonDrive, 5);
        self::assertSame(14400, $this->economy->shipSpeed(ShipType::Pfeil, $player), '+4 % per level');

        $player->setResearchLevel(ResearchType::IonDrive, 25);
        self::assertSame(24000, $this->economy->shipSpeed(ShipType::Pfeil, $player), 'level 25 doubles the speed');
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function researchStates(): iterable
    {
        yield 'no research' => [[]];
        yield 'ion drive maxed' => [['ion_drive' => 40]];
        yield 'warp drive maxed' => [['warp_drive' => 60]];
        yield 'solid drive low, warp high' => [['solid_drive' => 0, 'warp_drive' => 30, 'ion_drive' => 25]];
    }

    /** @param array<string, int> $levels */
    #[\PHPUnit\Framework\Attributes\DataProvider('researchStates')]
    public function testFusionatorIsFastestShipExceptScout(array $levels): void
    {
        $player = new User('pilot');
        foreach ($levels as $research => $level) {
            $player->setResearchLevel(ResearchType::from($research), $level);
        }

        $fusionator = $this->economy->shipSpeed(ShipType::Fusionator, $player);
        $scout = $this->economy->shipSpeed(ShipType::Scout, $player);
        self::assertGreaterThan($fusionator, $scout);
        foreach (ShipType::cases() as $type) {
            if (ShipType::Scout !== $type && ShipType::Fusionator !== $type) {
                self::assertGreaterThan($this->economy->shipSpeed($type, $player), $fusionator, $type->label());
            }
        }
    }

    public function testFusionCapacityIsCappedAtFortyNine(): void
    {
        $player = new User('schweisser');
        self::assertSame(0, $this->economy->fusionCapacity($player));

        $player->setResearchLevel(ResearchType::FusionTech, 7);
        self::assertSame(7, $this->economy->fusionCapacity($player));

        $player->setResearchLevel(ResearchType::FusionTech, 80);
        self::assertSame(49, $this->economy->fusionCapacity($player));
    }

    public function testCombatShipsComeFirstInFiringOrder(): void
    {
        self::assertSame(1, ShipType::Pfeil->firingOrder());
        self::assertSame(17, ShipType::HeavyBomber->firingOrder());
        self::assertSame(3, ShipType::LaserTurret->firingOrder());
        self::assertTrue(ShipType::LaserTurret->isStationary());
        self::assertFalse(ShipType::LaserTurret->isCombatShip());
        self::assertSame(ResearchType::ImpulseLaser, ShipType::Pfeil->weaponResearch());
        self::assertSame(ResearchType::QuantumShield, ShipType::Hammerhai->shieldResearch());
        self::assertNull(ShipType::Freighter->weaponResearch());
        $orders = array_map(static fn (ShipType $t): int => $t->firingOrder(), ShipType::cases());
        self::assertSame(\count($orders), \count(array_unique($orders)), 'every unit has its own place in the firing order');
        self::assertTrue(ShipType::HeavyBomber->isCombatShip());
        self::assertFalse(ShipType::Scout->isCombatShip());
        self::assertFalse(ShipType::Fusionator->isCombatShip());
    }

    /** Every ship and technology must be reachable: no requirement may point at itself or form a cycle. */
    public function testTechTreeHasNoCycles(): void
    {
        $visit = function (ResearchType $type, array $path) use (&$visit): void {
            self::assertNotContains($type, $path, 'Cycle at '.$type->label());
            foreach ($type->requirements() as $requirement) {
                if ($requirement->subject instanceof ResearchType) {
                    $visit($requirement->subject, [...$path, $type]);
                }
            }
        };
        foreach (ResearchType::cases() as $type) {
            $visit($type, []);
        }

        foreach (ShipType::cases() as $ship) {
            $spec = $ship->spec();
            self::assertContainsOnlyInstancesOf(Requirement::class, $spec->requirements);
            // Ships need a crew; defenses (turrets) need none.
            if ($ship->isStationary()) {
                self::assertSame(0, $spec->crew, $ship->label());
            } else {
                self::assertGreaterThan(0, $spec->crew, $ship->label());
            }
            self::assertGreaterThan(0, $spec->hull, $ship->label());
        }
    }

    public function testSystemsHaveTenToFiftyPlanetsMostlySmall(): void
    {
        $sizes = [];
        for ($galaxy = 1; $galaxy <= Universe::GALAXIES; ++$galaxy) {
            for ($system = 1; $system <= Universe::SYSTEMS_PER_GALAXY; ++$system) {
                $sizes[] = Universe::planetsInSystem($galaxy, $system);
            }
        }

        self::assertSame(10, min($sizes));
        self::assertLessThanOrEqual(50, max($sizes));
        self::assertGreaterThan(40, max($sizes), 'large systems exist too');
        $small = \count(array_filter($sizes, static fn (int $n): bool => $n < 30));
        self::assertGreaterThan(\count($sizes) / 2, $small, 'small systems are more common');
        self::assertGreaterThan(10, \count(array_unique($sizes)), 'sizes should vary between systems');
        self::assertSame(Universe::planetsInSystem(2, 17), Universe::planetsInSystem(2, 17));
    }

    public function testStartResourcesCoverTheStarterBuilds(): void
    {
        $colonizer = (new \ReflectionClass(\App\Game\Colonizer::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($colonizer, 'economy'))->setValue($colonizer, $this->economy);

        $stock = $colonizer->startResources();
        foreach (\App\Game\Colonizer::starterBuilds() as [$type, $level]) {
            $stock = $stock->subtract($this->economy->buildCost($type, $level));
        }

        foreach (\App\Game\ResourceType::cases() as $resource) {
            self::assertGreaterThanOrEqual(0, $stock->get($resource), $resource->label());
        }
        // Two farm and water pump levels feed a full level-1 bio cell (250 × 0.05 = 12.5 per hour).
        $planet = $this->planet();
        $planet->setBuildingLevel(BuildingType::Farm, 2)->setBuildingLevel(BuildingType::WaterPump, 2)->setBuildingLevel(BuildingType::BioCell, 1);
        $consumption = $this->economy->consumptionPerHour($this->economy->populationCapacity($planet));
        self::assertGreaterThan($consumption->food, $this->economy->productionPerHour($planet)->food);
        self::assertGreaterThan($consumption->water, $this->economy->productionPerHour($planet)->water);
    }

    private function planet(): Planet
    {
        return new Planet(new User('tester'), 'Test', 1, 1, 1, $this->start);
    }

    public function testResearchSpeedOnlyShortensResearch(): void
    {
        $planet = new Planet(new User('forscher'), 'Labor', 1, 1, 1, new \DateTimeImmutable('2026-01-01'));
        $planet->setBuildingLevel(BuildingType::ResearchLab, 2)->setBuildingLevel(BuildingType::Shipyard, 2);
        $normal = new Economy(1.0);
        $fastResearch = new Economy(1.0, 4.0);

        self::assertSame((int) round($normal->researchSeconds(ResearchType::Espionage, 3, $planet) / 4), $fastResearch->researchSeconds(ResearchType::Espionage, 3, $planet));
        self::assertSame($normal->shipSeconds(ShipType::Pfeil, $planet), $fastResearch->shipSeconds(ShipType::Pfeil, $planet), 'ships unaffected');
        self::assertSame($normal->buildSeconds(BuildingType::IronMine, 3, $planet), $fastResearch->buildSeconds(BuildingType::IronMine, 3, $planet), 'buildings unaffected');
    }
}
