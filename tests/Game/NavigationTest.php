<?php

namespace App\Tests\Game;

use App\Entity\Fleet;
use App\Entity\User;
use App\Game\Economy;
use App\Game\FleetMission;
use App\Game\Navigation;
use App\Game\Resources;
use App\Game\ResearchType;
use PHPUnit\Framework\TestCase;

class NavigationTest extends TestCase
{
    private Navigation $navigation;

    protected function setUp(): void
    {
        $this->navigation = new Navigation(new Economy(1.0));
    }

    public function testDistanceGrowsWithScope(): void
    {
        $samePlanet = $this->navigation->distance(1, 1, 5, 1, 1, 5);
        $sameSystem = $this->navigation->distance(1, 1, 5, 1, 1, 9);
        $sameGalaxy = $this->navigation->distance(1, 1, 5, 1, 20, 5);
        $otherGalaxy = $this->navigation->distance(1, 1, 5, 3, 1, 5);

        self::assertSame(1400, $sameSystem);
        self::assertSame(29500, $sameGalaxy);
        self::assertSame(200000, $otherGalaxy);
        self::assertTrue($samePlanet < $sameSystem && $sameSystem < $sameGalaxy && $sameGalaxy < $otherGalaxy);
    }

    public function testSlowestShipDeterminesSpeed(): void
    {
        $player = new User('pilot');
        $player->setResearchLevel(ResearchType::IonDrive, 0);

        // Freighter 6000 (solid drive), Pfeil 12000 (ion drive)
        self::assertSame(6000, $this->navigation->slowestSpeed(['freighter' => 2, 'pfeil' => 10], $player));
        self::assertSame(12000, $this->navigation->slowestSpeed(['pfeil' => 10], $player));
    }

    public function testFlightTimeFuelSuppliesAndCargo(): void
    {
        $ships = ['freighter' => 2];

        // 6000 distance at speed 6000 = 1 hour
        self::assertSame(3600, $this->navigation->flightSeconds(6000, 6000));
        // 2 freighters × 10 fuel, round trip, distance factor 1.6
        self::assertSame(64.0, $this->navigation->fuel($ships, 6000));
        self::assertSame(10000, $this->navigation->cargoCapacity($ships));
        self::assertSame(6, $this->navigation->crew($ships));
        // 6 crew × 0.05/h × 2 hours = 0.6 → rounded up to 1 each
        $supplies = $this->navigation->supplies($ships, 3600);
        self::assertSame(1.0, $supplies->food);
        self::assertSame(1.0, $supplies->water);
    }

    public function testRecallReturnsAfterTheTimeAlreadyFlown(): void
    {
        $start = new \DateTimeImmutable('2026-01-01 12:00:00');
        $user = new User('admiral');
        $fleet = new Fleet($user, null, 1, 1, 1, ['freighter' => 1], new Resources(iron: 100));
        $fleet->depart(FleetMission::Transport, 1, 1, 9, $start, 3600);

        self::assertEquals($start->modify('+1 hour'), $fleet->getArriveAt());
        self::assertEquals($start->modify('+2 hours'), $fleet->getReturnAt());

        $fleet->recall($start->modify('+20 minutes'));

        self::assertTrue($fleet->isReturning());
        self::assertEquals($start->modify('+40 minutes'), $fleet->getNextEventAt());
        self::assertSame(100.0, $fleet->getCargo()->iron);
    }

    public function testNamedFleetSettlesAtTargetAndMerges(): void
    {
        $start = new \DateTimeImmutable('2026-01-01 12:00:00');
        $user = new User('admiral');
        $fleet = new Fleet($user, 'Erste Flotte', 1, 1, 1, ['pfeil' => 5]);
        self::assertTrue($fleet->isIdle());

        $fleet->depart(FleetMission::Station, 2, 50, 7, $start, 600);
        self::assertTrue($fleet->isMoving());
        $fleet->settleAtTarget();

        self::assertTrue($fleet->isIdle());
        self::assertSame('2:50:7', $fleet->getCoordinates());
        self::assertNull($fleet->getTargetCoordinates());

        $fleet->merge(new Fleet($user, null, 1, 1, 1, ['pfeil' => 2, 'falke' => 1], new Resources(water: 10)));
        self::assertSame(['pfeil' => 7, 'falke' => 1], $fleet->getShips());
        self::assertSame(10.0, $fleet->getCargo()->water);
    }

    public function testOneWayCostsHalfTheRoundTrip(): void
    {
        $ships = ['freighter' => 2];
        self::assertSame(32.0, $this->navigation->fuel($ships, 6000, oneWay: true));
        self::assertSame(1.0, $this->navigation->supplies($ships, 3600, oneWay: true)->food);
    }
}
