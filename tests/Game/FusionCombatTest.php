<?php

namespace App\Tests\Game;

use App\Entity\Fleet;
use App\Entity\Message;
use App\Entity\Planet;
use App\Entity\User;
use App\Game\AccountDeletion;
use App\Game\AllianceService;
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
use App\Repository\UserRepository;
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
    private ?Planet $startPlanet = null;
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
        self::assertSame(0, $attackers->getShipCount(ShipType::InvasionShip), 'invasion ships are lost after every attempt');
        self::assertStringContainsString('Invasion gescheitert', $this->report());
    }

    public function testHomeworldCanNotBeInvaded(): void
    {
        $planet = new Planet($this->defender, 'Heimat', 1, 1, 5, $this->now);
        $planet->markHomeworld();

        $fleet = $this->attack($planet, ['leviathan' => 1, 'invasion_ship' => 3], lucky: true);

        self::assertSame($this->defender, $planet->getOwner());
        self::assertStringContainsString('Invasion fehlgeschlagen: Heimat [1:1:5] ist der Heimatplanet von verteidiger', $this->report());
        self::assertSame(3, $fleet->getShipCount(ShipType::InvasionShip), 'only at a home planet the invasion ships come back');
    }

    public function testHomeworldOfAnInactivePlayerFallsAndTakesAllHisPlanetsWithIt(): void
    {
        $this->defender->markSeen($this->now->modify('-31 days'));
        $home = new Planet($this->defender, 'Heimat', 1, 1, 5, $this->now);
        $home->markHomeworld();
        $home->setPopulation(10);
        $colony = new Planet($this->defender, 'Kolonie', 1, 1, 7, $this->now);

        $this->attack($home, ['leviathan' => 1, 'invasion_ship' => 3], lucky: true, deletion: true);

        self::assertSame($this->attacker, $home->getOwner());
        self::assertFalse($home->isHomeworld());
        self::assertFalse($home->isIntegrating($this->now), 'belongs to the conqueror at once');
        self::assertSame(1, $home->slotCost($this->now));
        self::assertSame(AccountDeletion::NOBODY, $colony->getOwner()->getUsername());
        self::assertCount(0, $this->defender->getPlanets());
        self::assertStringContainsString('verteidiger war inaktiv', $this->report());
        self::assertStringContainsString('Alle übrigen Planeten von verteidiger sind jetzt herrenlos', $this->report());
    }

    public function testAnInactivePlayersColonyTakesOneSlotAndKeepsHisOtherPlanets(): void
    {
        $this->defender->markSeen($this->now->modify('-31 days'));
        $home = new Planet($this->defender, 'Heimat', 1, 1, 5, $this->now);
        $home->markHomeworld();
        $colony = new Planet($this->defender, 'Kolonie', 1, 1, 7, $this->now);
        $colony->setPopulation(10);
        $this->attacker->setResearchLevel(ResearchType::Colonization, 1); // 10 slots, 9 taken: enough for 1, not for 3
        for ($i = 1; $i <= 9; ++$i) {
            new Planet($this->attacker, 'Eigen'.$i, 2, 2, $i, $this->now);
        }

        $this->attack($colony, ['leviathan' => 1, 'invasion_ship' => 3], lucky: true, deletion: true);

        self::assertSame($this->attacker, $colony->getOwner());
        self::assertSame(1, $colony->slotCost($this->now));
        self::assertSame($this->defender, $home->getOwner(), 'only the fall of the home planet frees the others');
    }

    public function testBombingCiviliansOfAnInnocentCostsHeroPoints(): void
    {
        $planet = new Planet($this->defender, 'Kolonie', 1, 1, 5, $this->now);
        $planet->setPopulation(5000);

        $this->attack($planet, ['leviathan' => 1, 'heavy_bomber' => 2], lucky: true);

        self::assertSame(4900.0, $planet->getPopulation(), '2 bombers × 50 inhabitants');
        self::assertEqualsWithDelta(990, $this->attacker->getHeroPoints(), 0.001, '100 dead = 10 crime points');
        self::assertSame(1000.0, $this->defender->getHeroPoints(), 'defending costs nothing');
        self::assertStringContainsString('angreifer −10 (jetzt 990)', $this->report());
    }

    public function testPlayersSeenWithinThirtyDaysOrOnVacationAreNotInactive(): void
    {
        $this->defender->markSeen($this->now->modify('-29 days'));
        self::assertFalse($this->defender->isInactive($this->now));
        $this->defender->markSeen($this->now->modify('-31 days'));
        self::assertTrue($this->defender->isInactive($this->now));
        $this->defender->startVacation($this->now->modify('-1 day'), $this->now->modify('+5 days'));
        self::assertFalse($this->defender->isInactive($this->now));
    }

    public function testInvasionFailsAtThePlanetLimit(): void
    {
        $this->attacker->setResearchLevel(ResearchType::Colonization, 0); // one slot: the home planet
        new Planet($this->attacker, 'Heim', 2, 2, 2, $this->now);
        $planet = new Planet($this->defender, 'Kolonie', 1, 1, 5, $this->now);
        $planet->setPopulation(10);

        $this->attack($planet, ['leviathan' => 1, 'invasion_ship' => 5], lucky: true);

        self::assertSame($this->defender, $planet->getOwner());
        self::assertStringContainsString('Invasion fehlgeschlagen: Ein invadierter Planet belegt 3 Planetenplätze (im Krieg 2) – angreifer hat nur noch 0 von 3 nötigen Planetenplätzen frei', $this->report());
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
    public function testSalvageShipsBringTheLootStraightToTheStartPlanet(): void
    {
        $planet = new Planet($this->defender, 'Speicher', 1, 1, 5, $this->now);
        $planet->setResources(new Resources(iron: 500000, titanium: 500000));
        $this->startPlanet = new Planet($this->attacker, 'Basis', 1, 2, 1, $this->now);
        $before = $this->startPlanet->getResources()->total();

        $withoutSalvager = $this->attack($planet, ['leviathan' => 1]);
        self::assertSame($before, $this->startPlanet->getResources()->total(), 'no salvage ship, no loot');

        $withSalvager = $this->attack($planet, ['leviathan' => 1, 'salvager' => 2]);
        self::assertSame(0.0, $withSalvager->getCargo()->total(), 'salvagers never carry anything');
        self::assertEqualsWithDelta($before + 2 * ShipType::Salvager->salvageCapacity(), $this->startPlanet->getResources()->total(), 2, 'the loot is at the start planet');

        self::assertNotNull(\App\Game\FleetService::freightersInWarfleet(['pfeil' => 5, 'large_freighter' => 1]));
        self::assertNull(\App\Game\FleetService::freightersInWarfleet(['pfeil' => 5, 'salvager' => 1]));
    }

    public function testJointInvasionAddsUpInvasionShipsAndAPlayerWithSlotsConquers(): void
    {
        $this->attacker->setResearchLevel(ResearchType::Colonization, 0); // no room left
        new Planet($this->attacker, 'Heim', 2, 2, 2, $this->now);
        $partner = new User('partner', $this->now);
        $partner->setResearchLevel(ResearchType::Colonization, 1);
        new Planet($partner, 'Basis', 2, 2, 3, $this->now);
        $planet = new Planet($this->defender, 'Ziel', 1, 1, 5, $this->now);
        $planet->setPopulation(40000);

        $joiner = new Fleet($partner, 'Helfer', 1, 3, 1, ['invasion_ship' => 1]);
        $joiner->depart(FleetMission::Attack, 1, 1, 5, $this->now, 60);
        $lead = $this->attack($planet, ['leviathan' => 1, 'invasion_ship' => 1], lucky: true, joiners: [$joiner]);

        self::assertSame($partner, $planet->getOwner(), 'only the partner has room for the planet');
        self::assertStringContainsString('2 Invasionsschiff(e) gegen 40.000 Einwohner', $this->report(), 'both fleets\' invasion ships count');
        self::assertSame(0, $lead->getShipCount(ShipType::InvasionShip));
        self::assertSame(0, $joiner->getShipCount(ShipType::InvasionShip));
    }

    public function testEscortsDefendTheirConvoyInARaid(): void
    {
        $pirate = new User('pirat', $this->now);
        $hunter = new Fleet($pirate, 'Jäger', 1, 1, 1, ['falke' => 20, 'heavy_bomber' => 1, 'salvager' => 1]);
        $hunter->depart(FleetMission::Raid, 1, 1, 9, $this->now, 60);
        $freighters = new Fleet($this->defender, 'Karawane', 1, 1, 2, ['large_freighter' => 2], new Resources(iron: 30000));
        $freighters->depart(FleetMission::Transport, 1, 1, 9, $this->now, 600);
        $escort = new Fleet($this->defender, 'Geleit', 1, 1, 2, ['korsar' => 10]);
        $escort->depart(FleetMission::Transport, 1, 1, 9, $this->now, 600);

        $this->combat(true)->raid($hunter, $freighters, [$escort]);

        self::assertStringContainsString('Im Konvoi flogen gemeinsam: Karawane (verteidiger), Geleit (verteidiger)', $this->report());
        self::assertLessThan(20, $hunter->getShipCount(ShipType::Falke), 'the escort fires back');
        self::assertSame(30000.0, $freighters->getCargo()->iron, 'the cargo is saved');
    }

    /** @param list<Fleet> $joiners */
    private function attack(Planet $target, array $ships, bool $lucky = true, array $joiners = [], bool $deletion = false): Fleet
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

        $this->combat($lucky, $deletion)->attackPlanet($fleet, $target, $joiners);

        return $fleet;
    }

    private function combat(bool $lucky, bool $deletion = false): CombatService
    {
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

        $planets = $this->createStub(\App\Repository\PlanetRepository::class);
        $planets->method('findAt')->willReturnCallback(fn (): ?Planet => $this->startPlanet);

        return new CombatService(
            $em,
            new PlanetService($em, new LockFactory(new InMemoryStore()), $clock, new PlanetUpdater($economy), $economy),
            $fleets,
            $debris,
            $economy,
            new Inbox($em, $clock),
            $clock,
            new Randomizer(new FixedEngine($lucky)),
            planetRepository: $planets,
            deletion: $deletion ? $this->deletion($em, $clock) : null,
        );
    }

    private function deletion(EntityManagerInterface $em, MockClock $clock): AccountDeletion
    {
        $nobody = new User(AccountDeletion::NOBODY, $this->now);
        $nobody->markSystem();
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturn($nobody);

        return new AccountDeletion($em, $users, $this->createStub(FleetRepository::class), new AllianceService(
            $em,
            $this->createStub(\App\Repository\AllianceRepository::class),
            $users,
            new Inbox($em, $clock),
            new LockFactory(new InMemoryStore()),
            $clock,
            new \App\Game\ImageStore(\Symfony\Component\Validator\Validation::createValidator(), sys_get_temp_dir()),
        ), $clock);
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
