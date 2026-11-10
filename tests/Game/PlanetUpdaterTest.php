<?php

namespace App\Tests\Game;

use App\Entity\BuildingOrder;
use App\Entity\Planet;
use App\Entity\ShipyardOrder;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\Economy;
use App\Game\PlanetType;
use App\Game\PlanetUpdater;
use App\Game\Universe;
use App\Game\ResourceType;
use App\Game\ShipType;
use App\Game\Resources;
use PHPUnit\Framework\TestCase;

class PlanetUpdaterTest extends TestCase
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

    public function testBaseProductionAccruesOverTime(): void
    {
        $planet = $this->planet();

        $this->updater->update($planet, $this->start->modify('+2 hours'));

        // Without buildings: 20 iron/h, 10 titanium/food/water per hour, no hydrogen.
        self::assertEqualsWithDelta(40, $planet->getResources()->iron, 0.001);
        self::assertEqualsWithDelta(20, $planet->getResources()->titanium, 0.001);
        self::assertEqualsWithDelta(20, $planet->getResources()->food, 0.001);
        self::assertEqualsWithDelta(20, $planet->getResources()->water, 0.001);
        self::assertEqualsWithDelta(0, $planet->getResources()->hydrogen, 0.001);
        self::assertEquals($this->start->modify('+2 hours'), $planet->getResourcesUpdatedAt());
    }

    public function testStorageIsUnlimited(): void
    {
        $planet = $this->planet(new Resources(iron: 1_000_000));

        $this->updater->update($planet, $this->start->modify('+10 hours'));

        self::assertEqualsWithDelta(1_000_200, $planet->getResources()->iron, 0.001);
    }

    public function testStorageBuildingRaisesProtectedAmount(): void
    {
        $planet = $this->planet();
        self::assertSame(10000.0, $this->economy->protectedAmount($planet));

        $planet->setBuildingLevel(BuildingType::Storage, 2);
        self::assertSame(22500.0, $this->economy->protectedAmount($planet));
    }

    public function testFinishedConstructionIsAppliedAndLaterProductionUsesNewLevel(): void
    {
        $planet = $this->planet();
        $this->queue($planet, BuildingType::IronMine, 1, 3600);

        $this->updater->update($planet, $this->start->modify('+2 hours'));

        self::assertSame(1, $planet->getBuildingLevel(BuildingType::IronMine));
        self::assertFalse($planet->isConstructing());
        // Hour 1: 20 base. Hour 2: 20 base + 25 * 1 * 1.12 from the new mine.
        self::assertEqualsWithDelta(20 + 20 + 28, $planet->getResources()->iron, 0.001);
    }

    public function testUnfinishedConstructionIsKept(): void
    {
        $planet = $this->planet();
        $this->queue($planet, BuildingType::IronMine, 1, 3600);

        $this->updater->update($planet, $this->start->modify('+30 minutes'));

        self::assertTrue($planet->isConstructing());
        self::assertSame(0, $planet->getBuildingLevel(BuildingType::IronMine));
    }

    public function testQueuedUpgradesRunOneAfterAnother(): void
    {
        $planet = $this->planet();
        $planet->setBuildingLevel(BuildingType::CommandCenter, 1);
        $first = $this->queue($planet, BuildingType::IronMine, 1, 3600);
        $planet->addBuildingOrder(new BuildingOrder($planet, BuildingType::IronMine, 2, new Resources(), 1));
        $planet->addBuildingOrder(new BuildingOrder($planet, BuildingType::WaterPump, 1, new Resources(), 2));

        // First done after 1h, the second starts right then and needs economy build time.
        $secondSeconds = $this->economy->buildSeconds(BuildingType::IronMine, 2, $planet);
        $this->updater->update($planet, $this->start->modify('+'.(3600 + $secondSeconds).' seconds'));

        self::assertSame(2, $planet->getBuildingLevel(BuildingType::IronMine));
        self::assertCount(1, $planet->getBuildingQueue());
        $third = $planet->getBuildingQueue()[0];
        self::assertSame(BuildingType::WaterPump, $third->getBuildingType());
        self::assertEquals($this->start->modify('+'.(3600 + $secondSeconds).' seconds'), $third->getStartedAt());
        self::assertNotSame($first, $third);
    }

    public function testSecondQueuedBuildingCostsThirtyPercentMore(): void
    {
        $first = $this->economy->queuedBuildCost(BuildingType::IronMine, 1, 0);
        $second = $this->economy->queuedBuildCost(BuildingType::IronMine, 1, 1);

        self::assertEquals(new Resources(iron: 60, titanium: 15), $first);
        self::assertEquals(new Resources(iron: 78, titanium: 20), $second, '60 × 1.3 = 78, 15 × 1.3 = 19.5 → 20');
        self::assertSame(2, \App\Game\QueueKind::Buildings->maxLength());
        self::assertNull(\App\Game\QueueKind::Shipyard->maxLength(), 'the shipyard queue is unlimited');
    }

    public function testPlannedLevelCountsQueuedUpgrades(): void
    {
        $planet = $this->planet();
        $planet->setBuildingLevel(BuildingType::IronMine, 3);
        $planet->addBuildingOrder(new BuildingOrder($planet, BuildingType::IronMine, 4, new Resources(), 0));
        $planet->addBuildingOrder(new BuildingOrder($planet, BuildingType::IronMine, 5, new Resources(), 1));

        self::assertSame(5, $planet->getPlannedBuildingLevel(BuildingType::IronMine));
        self::assertSame(0, $planet->getPlannedBuildingLevel(BuildingType::Farm));
    }

    public function testQueueEntryWithUnmetRequirementsIsRefundedWhenItStarts(): void
    {
        $planet = $this->planet(new Resources(iron: 0));
        // Shipyard needs command center 3, which the planet doesn't have.
        $planet->addBuildingOrder(new BuildingOrder($planet, BuildingType::Shipyard, 1, new Resources(iron: 400, titanium: 200), 0));

        $this->updater->update($planet, $this->start->modify('+1 second'));

        self::assertCount(0, $planet->getBuildingQueue());
        self::assertEqualsWithDelta(400, $planet->getResources()->iron, 0.1);
    }

    public function testPausedWorkIsResumedWithoutLosingProgress(): void
    {
        $planet = $this->planet();
        $order = $this->queue($planet, BuildingType::IronMine, 1, 3600);

        $order->stop($this->start->modify('+40 minutes'));
        self::assertFalse($order->isStarted());
        self::assertSame(2400, $order->getProgressSeconds());

        $order->start($this->start->modify('+2 hours'), 9999);
        self::assertEquals($this->start->modify('+2 hours 20 minutes'), $order->getFinishesAt());
    }

    public function testSwapPayloadKeepsLevelsAscending(): void
    {
        $planet = $this->planet();
        $low = new BuildingOrder($planet, BuildingType::Farm, 3, new Resources(iron: 100), 1);
        $high = new BuildingOrder($planet, BuildingType::Farm, 4, new Resources(iron: 150), 0);

        $high->swapPayload($low);

        self::assertSame(3, $high->getTargetLevel());
        self::assertSame(100.0, $high->getCost()->iron);
        self::assertSame(4, $low->getTargetLevel());
    }

    public function testShipyardOrderResumesProgress(): void
    {
        $planet = $this->planet();
        $order = new ShipyardOrder($planet, ShipType::Pfeil, 3, 600, $this->start);
        $planet->addShipyardOrder($order);

        $order->stop($this->start->modify('+5 minutes'));
        $order->start($this->start->modify('+1 hour'));

        self::assertEquals($this->start->modify('+1 hour 5 minutes'), $order->getNextShipAt());
    }

    public function testBuildCostAndTimeGrowWithLevel(): void
    {
        $planet = $this->planet();

        self::assertEquals(new Resources(iron: 60, titanium: 15), $this->economy->buildCost(BuildingType::IronMine, 1));
        self::assertEquals(new Resources(iron: 90, titanium: 22), $this->economy->buildCost(BuildingType::IronMine, 2));
        self::assertGreaterThan(
            $this->economy->buildSeconds(BuildingType::IronMine, 1, $planet),
            $this->economy->buildSeconds(BuildingType::IronMine, 5, $planet),
        );
    }

    public function testEachBuildingFeedsItsResource(): void
    {
        $planet = $this->planet();
        $before = $this->economy->productionPerHour($planet);

        foreach ([
            [BuildingType::IronMine, ResourceType::Iron],
            [BuildingType::TitaniumMine, ResourceType::Titanium],
            [BuildingType::Farm, ResourceType::Food],
            [BuildingType::WaterPump, ResourceType::Water],
            [BuildingType::Electrolysis, ResourceType::Hydrogen],
        ] as [$building, $resource]) {
            $planet->setBuildingLevel($building, 1);
            self::assertGreaterThan($before->get($resource), $this->economy->productionPerHour($planet)->get($resource), $building->label());
        }
    }

    public function testPopulationConsumesFoodAndWater(): void
    {
        $planet = $this->planet(new Resources(food: 1000, water: 1000));
        $planet->setBuildingLevel(BuildingType::BioCell, 1);
        $planet->setPopulation(250); // full, so no growth

        $this->updater->update($planet, $this->start->modify('+2 hours'));

        // 2h: +10/h base production, -250 * 0.05/h consumption.
        self::assertEqualsWithDelta(1000 + 20 - 25, $planet->getResources()->food, 0.001);
        self::assertEqualsWithDelta(1000 + 20 - 25, $planet->getResources()->water, 0.001);
        self::assertEqualsWithDelta(250, $planet->getPopulation(), 0.001);
    }

    public function testPopulationGrowsUpToBioCellCapacity(): void
    {
        $planet = $this->planet(new Resources(food: 10_000, water: 10_000), PlanetType::Water);
        $planet->setBuildingLevel(BuildingType::BioCell, 1);
        $planet->setPopulation(100);

        $this->updater->update($planet, $this->start->modify('+4 hours'));
        // 5 % of 250 capacity = 12.5 per hour.
        self::assertEqualsWithDelta(150, $planet->getPopulation(), 0.001);

        $this->updater->update($planet, $this->start->modify('+48 hours'));
        self::assertSame(250.0, $planet->getPopulation());
    }

    public function testOvercrowdedPopulationShrinksGradually(): void
    {
        $planet = $this->planet(new Resources(food: 100_000, water: 100_000));
        $planet->setBuildingLevel(BuildingType::BioCell, 1);
        $planet->setPopulation(1000); // capacity 250

        $this->updater->update($planet, $this->start->modify('+1 hour'));
        self::assertGreaterThan(850, $planet->getPopulation());
        self::assertLessThan(1000, $planet->getPopulation());

        $this->updater->update($planet, $this->start->modify('+48 hours'));
        self::assertSame(250.0, $planet->getPopulation());
    }

    public function testNoPopulationWithoutBioCell(): void
    {
        $planet = $this->planet(new Resources(food: 1000, water: 1000));

        $this->updater->update($planet, $this->start->modify('+10 hours'));

        self::assertSame(0.0, $planet->getPopulation());
        self::assertSame(0.0, $this->economy->populationCapacity($planet));
    }

    public function testStarvingPopulationShrinksToWhatCanBeSupplied(): void
    {
        $planet = $this->planet(new Resources(food: 0, water: 100_000), PlanetType::Water);
        $planet->setBuildingLevel(BuildingType::BioCell, 1);
        $planet->setPopulation(250);

        $this->updater->update($planet, $this->start->modify('+1 hour'));
        self::assertLessThan(250, $planet->getPopulation());
        self::assertGreaterThanOrEqual(0.0, $planet->getResources()->food);

        $this->updater->update($planet, $this->start->modify('+72 hours'));
        // 10 food per hour feeds 200 inhabitants at 0.05 each.
        self::assertEqualsWithDelta(200, $planet->getPopulation(), 2);
        self::assertGreaterThanOrEqual(0.0, $planet->getResources()->food);
    }

    public function testBunkerCapacity(): void
    {
        $planet = $this->planet();
        self::assertSame(0.0, $this->economy->bunkerCapacity($planet));

        $planet->setBuildingLevel(BuildingType::Bunker, 1);
        self::assertSame(100.0, $this->economy->bunkerCapacity($planet));

        $planet->setBuildingLevel(BuildingType::Bunker, 2);
        self::assertSame(160.0, $this->economy->bunkerCapacity($planet));
    }

    public function testRequirements(): void
    {
        $planet = $this->planet();
        $planet->setBuildingLevel(BuildingType::CommandCenter, 1);

        self::assertSame(['Kommandozentrale Stufe 3'], array_map(self::text(...), $this->economy->missingRequirements(BuildingType::Shipyard->requirements(), $planet)));

        $planet->setBuildingLevel(BuildingType::CommandCenter, 3);
        self::assertSame([], $this->economy->missingRequirements(BuildingType::Shipyard->requirements(), $planet));
    }

    private function queue(Planet $planet, BuildingType $type, int $level, int $seconds): BuildingOrder
    {
        $order = new BuildingOrder($planet, $type, $level, new Resources(), \count($planet->getBuildingQueue()));
        $planet->addBuildingOrder($order);
        $order->start($this->start, $seconds);

        return $order;
    }

    /**
     * A planet on a slot of the given type. Default "blue": food and water are
     * unchanged there; tests about growth pick a type without the growth bonus.
     */
    private function planet(Resources $resources = new Resources(), PlanetType $type = PlanetType::Blue): Planet
    {
        $planet = new Planet(new User('tester'), 'Test', 1, 1, self::slotOf($type), $this->start);
        $planet->setResources($resources);

        return $planet;
    }

    /** First position in system 1:1 that has the given planet type. */
    public static function slotOf(PlanetType $type): int
    {
        for ($position = 1; $position <= 30; ++$position) {
            if (Universe::planetType(1, 1, $position) === $type) {
                return $position;
            }
        }

        throw new \LogicException('No slot of type '.$type->value);
    }

    public function testPlanetTypesChangeProductionAndGrowth(): void
    {
        $production = [];
        $growth = [];
        foreach (PlanetType::cases() as $type) {
            $planet = $this->planet(type: $type);
            $planet->setBuildingLevel(BuildingType::BioCell, 1);
            $production[$type->value] = $this->economy->productionPerHour($planet);
            $growth[$type->value] = $this->economy->populationGrowthPerHour($planet);
        }

        // Base production: 10 food and 10 water per hour.
        self::assertEqualsWithDelta(15, $production['red']->food, 0.001);
        self::assertEqualsWithDelta(15, $production['water']->water, 0.001);
        self::assertEqualsWithDelta(5, $production['stone']->food, 0.001);
        self::assertEqualsWithDelta(5, $production['desert']->food, 0.001);
        self::assertEqualsWithDelta(5, $production['desert']->water, 0.001);
        self::assertEqualsWithDelta(10, $production['blue']->food, 0.001);
        self::assertEqualsWithDelta(20, $production['stone']->iron, 0.001, 'iron is never affected');

        self::assertEqualsWithDelta(2 * $growth['red'], $growth['blue'], 0.001);
        self::assertEqualsWithDelta($growth['red'], $growth['desert'], 0.001);
    }

    public function testSlotTypesAreFixedAndAllTypesOccur(): void
    {
        self::assertSame(Universe::planetType(2, 77, 13), Universe::planetType(2, 77, 13));

        $seen = [];
        for ($position = 1; $position <= 50; ++$position) {
            $seen[Universe::planetType(1, 7, $position)->value] = true;
        }
        self::assertCount(\count(PlanetType::cases()), $seen);
    }
}
