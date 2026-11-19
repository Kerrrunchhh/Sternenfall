<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\Economy;
use App\Game\PlanetService;
use App\Game\PlanetUpdater;
use App\Game\ResearchType;
use App\Game\Resources;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Research is paid by the planet it is started on – the player's other labs pay nothing. */
final class ResearchPaysOnePlanetTest extends TestCase
{
    public function testOnlyThePlanetTheResearchStartsOnPays(): void
    {
        $now = new \DateTimeImmutable('2026-11-19 12:00:00');
        $player = new User('forscherin', $now);
        $home = new Planet($player, 'Heim', 1, 1, 1, $now);
        $colony = new Planet($player, 'Kolonie', 1, 1, 2, $now);
        foreach ([$home, $colony] as $planet) {
            $planet->setBuildingLevel(BuildingType::CommandCenter, 5);
            $planet->setBuildingLevel(BuildingType::ResearchLab, 3);
            $planet->setResources(new Resources(iron: 100000, titanium: 100000, food: 100000, water: 100000, hydrogen: 100000));
        }
        $economy = new Economy(1.0);
        $type = ResearchType::Espionage;
        $cost = $economy->researchCost($type, 1);
        $service = new PlanetService($this->createStub(EntityManagerInterface::class), new LockFactory(new InMemoryStore()), new MockClock($now), new PlanetUpdater($economy), $economy);

        $colonyBefore = $colony->getResources();
        $homeBefore = $home->getResources();
        $service->research($home, $type);

        self::assertTrue($player->isResearching());
        self::assertEquals($homeBefore->subtract($cost)->toArray(), $home->getResources()->toArray(), 'the home planet pays the research');
        self::assertEquals($colonyBefore->toArray(), $colony->getResources()->toArray(), 'the colony keeps its resources');
    }
}
