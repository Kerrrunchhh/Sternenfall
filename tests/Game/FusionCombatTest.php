<?php

namespace App\Tests\Game;

use App\Entity\Fleet;
use App\Entity\Message;
use App\Entity\Planet;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\CombatService;
use App\Game\Economy;
use App\Game\FleetMission;
use App\Game\Fusion;
use App\Game\Inbox;
use App\Game\PlanetService;
use App\Game\PlanetUpdater;
use App\Game\ResearchType;
use App\Game\Resources;
use App\Game\ShipType;
use App\Repository\DebrisFieldRepository;
use App\Repository\FleetRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Random\Engine;
use Random\Randomizer;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Always rolls the same number: 0.0 (every chance succeeds) or just below 1.0 (every chance fails). */
final class FixedEngine implements Engine
{
    public function __construct(private readonly bool $lucky)
    {
    }

    public function generate(): string
    {
        return str_repeat($this->lucky ? "\x00" : "\xff", 8);
    }
}

class FusionCombatTest extends TestCase
{
    private \DateTimeImmutable $now;
    private User $defender;
    private User $attacker;
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01 12:00:00');
        $this->defender = new User('verteidiger', $this->now);
        $this->attacker = new User('angreifer', $this->now);
        $this->attacker->setResearchLevel(\App\Game\ResearchType::Colonization, 10);
        $this->persisted = [];
    }

    public function testShieldHoldsAgainstWeakerAttack(): void
    {
        $fusion = $this->fusion(4, shieldLevels: 3); // 60.000 shield
        $this->attack($fusion, ['leviathan' => 1]); // 40.000 attack

        self::assertSame(4, $fusion->getFusionSize());
        self::assertStringContainsString('Schild der Fusion hält', $this->report());
    }

    public function testAttackThroughTheShieldBreaksOffTheBottomPlanet(): void
    {
        $fusion = $this->fusion(4, shieldLevels: 1); // 20.000 shield
        $this->attack($fusion, ['leviathan' => 1]);

        self::assertSame(3, $fusion->getFusionSize());
        $broken = $this->newPlanets()[0];
        self::assertSame(4, $broken->getPosition());
        self::assertSame($this->defender, $broken->getOwner(), 'without invasion the planet stays with the defender');
        self::assertEqualsWithDelta(1000, $broken->getResources()->iron, 0.01, 'a quarter of the resources');
        self::assertStringContainsString('Schild durchbrochen', $this->report());
    }

    public function testSuccessfulInvasionOfAFusionTakesOnlyOnePlanetAndResearchGoesOn(): void
    {
        $fusion = $this->fusion(4, shieldLevels: 10); // shield holds, invasion still breaks one planet off
        $this->defender->startResearch(ResearchType::Espionage, 1, $this->now->modify('+5 days'), $fusion);

        $this->attack($fusion, ['leviathan' => 1, 'invasion_ship' => 1], lucky: true);

        self::assertSame(3, $fusion->getFusionSize());
        self::assertSame($this->defender, $fusion->getOwner());
        $taken = $this->newPlanets()[0];
        self::assertSame(4, $taken->getPosition());
        self::assertSame($this->attacker, $taken->getOwner());
        self::assertTrue($this->defender->isResearching(), 'research keeps running on the top planet');
        self::assertStringContainsString('Invasion gelungen', $this->report());
    }

    public function testShieldBreakAndInvasionCostTheFusionOnlyOnePlanet(): void
    {
        $fusion = $this->fusion(4, shieldLevels: 0);
        $this->attack($fusion, ['leviathan' => 1, 'invasion_ship' => 1], lucky: true);

        self::assertSame(3, $fusion->getFusionSize());
        self::assertCount(1, $this->newPlanets());
        self::assertSame($this->attacker, $this->newPlanets()[0]->getOwner());
    }

    public function testInvadingTheLastPlanetEndsTheResearch(): void
    {
        $planet = new Planet($this->defender, 'Letzter', 1, 1, 5, $this->now);
        $planet->setPopulation(5000);
        $this->defender->startResearch(ResearchType::Espionage, 1, $this->now->modify('+5 days'), $planet);

        $this->attack($planet, ['leviathan' => 1, 'invasion_ship' => 1], lucky: true);

        self::assertSame($this->attacker, $planet->getOwner());
        self::assertFalse($this->defender->isResearching());
    }

    public function testInvasionCanFail(): void
    {
        $planet = new Planet($this->defender, 'Zäh', 1, 1, 5, $this->now);
        $planet->setPopulation(5000);

        $attackers = $this->attack($planet, ['leviathan' => 1, 'invasion_ship' => 1], lucky: false);

        self::assertSame($this->defender, $planet->getOwner());
        self::assertSame(1, $attackers->getShipCount(ShipType::InvasionShip), 'the invasion ships fly back');
        self::assertStringContainsString('Invasion gescheitert', $this->report());
    }

    public function testHomeworldCanNotBeInvaded(): void
    {
        $planet = new Planet($this->defender, 'Heimat', 1, 1, 5, $this->now);
        $planet->markHomeworld();

        $this->attack($planet, ['leviathan' => 1, 'invasion_ship' => 3], lucky: true);

        self::assertSame($this->defender, $planet->getOwner());
        self::assertStringContainsString('Invasion fehlgeschlagen: Heimat [1:1:5] ist der Heimatplanet von verteidiger', $this->report());
    }

    public function testInvasionFailsAtThePlanetLimit(): void
    {
        $this->attacker->setResearchLevel(ResearchType::Colonization, 0); // one slot: the home planet
        new Planet($this->attacker, 'Heim', 2, 2, 2, $this->now);
        $planet = new Planet($this->defender, 'Kolonie', 1, 1, 5, $this->now);
        $planet->setPopulation(10);

        $this->attack($planet, ['leviathan' => 1, 'invasion_ship' => 5], lucky: true);

        self::assertSame($this->defender, $planet->getOwner());
        self::assertStringContainsString('Invasion fehlgeschlagen: angreifer ist am Planetenlimit (1 von 1 Planetenplätzen belegt', $this->report());
    }

    private function fusion(int $size, int $shieldLevels): Planet
    {
        $top = new Planet($this->defender, 'P1', 1, 1, 1, $this->now);
        $top->setResources(new Resources(iron: 4000))->setPopulation(1000);
        $top->setBuildingLevel(BuildingType::ShieldGenerator, $shieldLevels);
        $top->markHomeworld();
        $chain = [];
        for ($position = 2; $position <= $size; ++$position) {
            $chain[] = new Planet($this->defender, 'P'.$position, 1, 1, $position, $this->now);
        }
        Fusion::merge($top, $chain);
        foreach ($chain as $planet) {
            $this->defender->getPlanets()->removeElement($planet);
        }

        return $top;
    }

    /** @param array<string, int> $ships */
    private function attack(Planet $target, array $ships, bool $lucky = true): Fleet
    {
        $fleet = new Fleet($this->attacker, 'Sturm', 1, 2, 1, $ships);
        $fleet->depart(FleetMission::Attack, $target->getGalaxy(), $target->getSystem(), $target->getPosition(), $this->now, 60);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $clock = new MockClock($this->now);
        $economy = new Economy(1.0);
        $fleets = $this->createStub(FleetRepository::class);
        $fleets->method('findIdleOf')->willReturn([]);
        $debris = $this->createStub(DebrisFieldRepository::class);
        $debris->method('findAt')->willReturn(null);

        $combat = new CombatService(
            $em,
            new PlanetService($em, new LockFactory(new InMemoryStore()), $clock, new PlanetUpdater($economy), $economy),
            $fleets,
            $debris,
            $economy,
            new Inbox($em, $clock),
            $clock,
            new Randomizer(new FixedEngine($lucky)),
        );
        $combat->attackPlanet($fleet, $target);

        return $fleet;
    }

    /** @return list<Planet> */
    private function newPlanets(): array
    {
        return array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Planet));
    }

    private function report(): string
    {
        $messages = array_filter($this->persisted, static fn (object $o): bool => $o instanceof Message);

        return implode("\n---\n", array_map(static fn (Message $m): string => $m->getBody(), $messages));
    }
}
