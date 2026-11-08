<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\ShipyardOrder;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\Economy;
use App\Game\GameException;
use App\Game\LifeForm;
use App\Game\PlanetService;
use App\Game\PlanetUpdater;
use App\Game\QueueKind;
use App\Game\ResearchType;
use App\Game\Resources;
use App\Game\ShipType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class BuildRateTest extends TestCase
{
    private Economy $economy;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->economy = new Economy(1.0);
        $this->now = new \DateTimeImmutable('2026-01-01 12:00:00');
    }

    public function testHigherRateCostsMoreAndSecuresFoodWaterHydrogen(): void
    {
        // Pfeil: 3000 iron, 1000 titanium (settlers pay no credits).
        $settler = new User('siedler', $this->now);

        self::assertEquals(new Resources(iron: 3000, titanium: 1000), $this->economy->shipOrderCost(ShipType::Pfeil, $settler, 100));
        // 200 %: everything doubled; extra iron 3000 → 3000 food + 3000 water + 300 hydrogen.
        self::assertEquals(
            new Resources(iron: 6000, titanium: 2000, food: 3000, water: 3000, hydrogen: 300),
            $this->economy->shipOrderCost(ShipType::Pfeil, $settler, 200),
        );
        // 125 %: extra iron 750 → 750 food + 750 water + 75 hydrogen.
        self::assertEquals(
            new Resources(iron: 3750, titanium: 1250, food: 750, water: 750, hydrogen: 75),
            $this->economy->shipOrderCost(ShipType::Pfeil, $settler, 125),
        );
    }

    public function testCreditsRiseOnlyForDefenses(): void
    {
        $guardian = new User('wächter', $this->now);
        $guardian->changeLifeForm(LifeForm::Guardian, $this->now);

        $shipCredits = $this->economy->shipCredits(ShipType::Pfeil, $guardian);
        self::assertSame($shipCredits, $this->economy->shipOrderCredits(ShipType::Pfeil, $guardian, 200), 'ships: credits unchanged');

        $turretCredits = $this->economy->shipCredits(ShipType::LaserTurret, $guardian);
        self::assertSame(ceil($turretCredits * 1.75), $this->economy->shipOrderCredits(ShipType::LaserTurret, $guardian, 175), 'defenses: credits × rate');
    }

    public function testBuildTimeShrinksWithTheRate(): void
    {
        $planet = new Planet(new User('bauherr', $this->now), 'Werft', 1, 1, 1, $this->now);
        $planet->setBuildingLevel(BuildingType::Shipyard, 1);
        $base = $this->economy->shipSeconds(ShipType::Pfeil, $planet);

        self::assertSame($base, $this->economy->shipOrderSeconds(ShipType::Pfeil, $planet, 100));
        self::assertSame((int) round($base / 2), $this->economy->shipOrderSeconds(ShipType::Pfeil, $planet, 200));
        self::assertSame((int) round($base / 1.5), $this->economy->shipOrderSeconds(ShipType::Pfeil, $planet, 150));
    }

    public function testOnlyTheFiveRatesAreAllowed(): void
    {
        $this->expectException(GameException::class);
        $this->service()->orderShips($this->shipyardPlanet(), ShipType::Pfeil, 1, 130);
    }

    public function testOrderAtRateBindsResourcesAndCancelRefundsEverything(): void
    {
        $planet = $this->shipyardPlanet();
        $before = $planet->getResources();
        $service = $this->service();

        $service->orderShips($planet, ShipType::Pfeil, 2, 200);

        $order = $planet->getShipyardQueue()[0];
        self::assertSame(200, $order->getPercent());
        self::assertSame((int) round($this->economy->shipSeconds(ShipType::Pfeil, $planet) / 2), $order->getUnitSeconds());
        $paid = $before->subtract($planet->getResources());
        self::assertEqualsWithDelta(12000, $paid->iron, 0.01);
        self::assertEqualsWithDelta(6000, $paid->food, 0.01);
        self::assertEqualsWithDelta(6000, $paid->water, 0.01);
        self::assertEqualsWithDelta(600, $paid->hydrogen, 0.01);

        (new \ReflectionProperty(ShipyardOrder::class, 'id'))->setValue($order, 7);
        $service->cancelOrder($planet, QueueKind::Shipyard, 7);

        self::assertSame([], $planet->getShipyardQueue());
        foreach (['iron', 'titanium', 'food', 'water', 'hydrogen'] as $resource) {
            self::assertEqualsWithDelta($before->{$resource}, $planet->getResources()->{$resource}, 0.01, $resource);
        }
    }

    public function testDefenseOrdersHaveNoQuantityLimit(): void
    {
        $planet = $this->shipyardPlanet();
        $planet->setPopulation(1_000_000)->setResources(new Resources(iron: 1e9, titanium: 1e9));
        $planet->getOwner()->setResearchLevel(ResearchType::SolidDrive, 1);

        $this->service()->orderShips($planet, ShipType::LaserTurret, 150_000);
        self::assertSame(150_000, $planet->getDefenseQueue()[0]->getQuantity());

        $this->expectException(GameException::class);
        $this->service()->orderShips($planet, ShipType::Pfeil, PlanetService::MAX_ORDER_QUANTITY + 1);
    }

    public function testDefensesNeedNoCrew(): void
    {
        foreach (ShipType::cases() as $type) {
            if ($type->isStationary()) {
                self::assertSame(0, $type->spec()->crew, $type->label());
            }
        }
        $planet = $this->shipyardPlanet();
        $planet->setPopulation(0);
        $planet->getOwner()->setResearchLevel(ResearchType::SolidDrive, 1);

        $this->service()->orderShips($planet, ShipType::LaserTurret, 10);
        self::assertSame(10, $planet->getDefenseQueue()[0]->getQuantity());
    }

    public function testShipsAndDefensesAreBuiltInParallel(): void
    {
        $planet = $this->shipyardPlanet();
        $planet->getOwner()->setResearchLevel(ResearchType::SolidDrive, 1);
        $service = $this->service();

        $service->orderShips($planet, ShipType::Pfeil, 1);
        $service->orderShips($planet, ShipType::LaserTurret, 1);

        self::assertCount(1, $planet->getShipyardQueue());
        self::assertCount(1, $planet->getDefenseQueue());
        self::assertTrue($planet->getShipyardQueue()[0]->isStarted(), 'the ship starts');
        self::assertTrue($planet->getDefenseQueue()[0]->isStarted(), 'the turret starts at the same time');

        // After the longer of both build times, both are done.
        $seconds = max($planet->getShipyardQueue()[0]->getUnitSeconds(), $planet->getDefenseQueue()[0]->getUnitSeconds());
        (new PlanetUpdater($this->economy))->update($planet, $this->now->modify("+{$seconds} seconds"));
        self::assertSame(1, $planet->getShipCount(ShipType::Pfeil));
        self::assertSame(1, $planet->getShipCount(ShipType::LaserTurret));
    }

    private function shipyardPlanet(): Planet
    {
        $player = new User('werftchef', $this->now);
        $player->setResearchLevel(ResearchType::IonDrive, 1);
        $player->setResearchLevel(ResearchType::ImpulseLaser, 1);
        $planet = new Planet($player, 'Werft', 1, 1, 1, $this->now);
        $planet->setBuildingLevel(BuildingType::Shipyard, 2)->setPopulation(1000);
        $planet->setResources(new Resources(iron: 50_000, titanium: 50_000, food: 50_000, water: 50_000, hydrogen: 50_000));

        return $planet;
    }

    private function service(): PlanetService
    {
        return new PlanetService(
            $this->createStub(EntityManagerInterface::class),
            new LockFactory(new InMemoryStore()),
            new MockClock($this->now),
            new PlanetUpdater($this->economy),
            $this->economy,
        );
    }
}
