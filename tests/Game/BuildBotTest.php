<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\BuildBot\BuildBotSettings;
use App\Game\BuildingType;
use App\Game\Economy;
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

/** The build bot fills a planet's queues at the press of a button. */
class BuildBotTest extends TestCase
{
    private Economy $economy;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->economy = new Economy(1.0);
        $this->now = new \DateTimeImmutable('2026-01-01 12:00:00');
    }

    public function testGoalsInOrderThenTheDefault(): void
    {
        $planet = $this->planet();
        $planet->setBuildingLevel(BuildingType::IronMine, 5)->setBuildingLevel(BuildingType::Farm, 3);
        $planet->getOwner()->setBuildBot(BuildBotSettings::fromArray([
            'goals' => [['building' => 'iron_mine', 'level' => 5], ['building' => 'farm', 'level' => 4], ['building' => 'water_pump', 'level' => 1]],
            'default' => 'bio_cell',
        ]));

        $placed = $this->service()->fillBuildingQueue($planet);

        // The iron mine is there already; the farm needs one more level, then the water pump.
        self::assertSame([[BuildingType::Farm, 4], [BuildingType::WaterPump, 1]], array_map(static fn (array $p): array => [$p['building'], $p['level']], $placed));
        self::assertCount(2, $planet->getBuildingQueue(), 'the queue is full');

        // Once every goal is reached, the default building follows.
        $planet->getOwner()->setBuildBot(BuildBotSettings::fromArray(['goals' => [['building' => 'farm', 'level' => 4]], 'default' => 'bio_cell']));
        self::assertSame(BuildingType::BioCell, $planet->getOwner()->getBuildBot()->nextBuilding(
            static fn (BuildingType $b): int => $planet->getPlannedBuildingLevel($b),
            static fn (): bool => true,
        ));
    }

    public function testNothingToDoWithoutGoalsAndDefault(): void
    {
        $planet = $this->planet();
        $planet->getOwner()->setBuildBot(BuildBotSettings::fromArray(['goals' => [['building' => 'farm', 'level' => 1]]]));
        $planet->setBuildingLevel(BuildingType::Farm, 1);

        self::assertSame([], $this->service()->fillBuildingQueue($planet));
        self::assertSame([], $planet->getBuildingQueue());
    }

    public function testShipsAsManyAsAffordableOptionallyAfterClearing(): void
    {
        $planet = $this->planet();
        $planet->getOwner()->setBuildBot(BuildBotSettings::fromArray(['queues' => ['shipyard' => ['ship' => 'pfeil', 'percent' => 100, 'clear' => false]]]));
        // Pfeil: 3000 iron, 1000 titanium for a settler – 50,000 iron give 16.
        $service = $this->service();

        self::assertSame(16, $service->fillShipQueue($planet, QueueKind::Shipyard));
        self::assertCount(1, $planet->getQueue(QueueKind::Shipyard));
        self::assertSame(0, $service->fillShipQueue($planet, QueueKind::Shipyard), 'nothing left to pay with');

        // Clearing first gives everything back and orders it again – at 200 % this time.
        $planet->getOwner()->setBuildBot(BuildBotSettings::fromArray(['queues' => ['shipyard' => ['ship' => 'pfeil', 'percent' => 200, 'clear' => true]]]));
        self::assertSame(8, $service->fillShipQueue($planet, QueueKind::Shipyard));
        self::assertCount(1, $planet->getQueue(QueueKind::Shipyard));
        self::assertSame(200, $planet->getQueue(QueueKind::Shipyard)[0]->getPercent());
    }

    public function testSettingsKeepOnlyWhatFits(): void
    {
        $settings = BuildBotSettings::fromArray([
            'goals' => [['building' => 'farm', 'level' => 2], ['building' => 'nonsense', 'level' => 3], ['building' => 'iron_mine', 'level' => 0], ['building' => 'storage', 'level' => 1], ['building' => 'bunker', 'level' => 1]],
            'queues' => ['shipyard' => ['ship' => 'laser_turret', 'percent' => 130], 'defense' => ['ship' => 'laser_turret', 'percent' => 150, 'clear' => '1']],
        ]);

        self::assertSame([['building' => BuildingType::Farm, 'level' => 2]], $settings->goals, 'three slots at most (the 4th and 5th are ignored), unknown or empty ones dropped');
        self::assertNull($settings->queue(QueueKind::Shipyard)['ship'], 'a turret belongs in the defense queue');
        self::assertSame(100, $settings->queue(QueueKind::Shipyard)['percent'], 'only the build rates of the game');
        self::assertSame(['ship' => ShipType::LaserTurret, 'percent' => 150, 'clear' => true], $settings->queue(QueueKind::Defense));
        self::assertEquals($settings, BuildBotSettings::fromArray($settings->toArray()));
    }

    private function planet(): Planet
    {
        $player = new User('bauherr', $this->now);
        $player->setResearchLevel(ResearchType::IonDrive, 1);
        $player->setResearchLevel(ResearchType::ImpulseLaser, 1);
        $planet = new Planet($player, 'Werft', 1, 1, 1, $this->now);
        $planet->setBuildingLevel(BuildingType::Shipyard, 2)->setBuildingLevel(BuildingType::CommandCenter, 5)->setPopulation(1000);
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
