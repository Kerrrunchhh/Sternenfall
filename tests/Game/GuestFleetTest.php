<?php

namespace App\Tests\Game;

use App\Entity\Alliance;
use App\Entity\Fleet;
use App\Entity\Message;
use App\Entity\Planet;
use App\Entity\User;
use App\Game\AllianceRank;
use App\Game\CombatService;
use App\Game\Colonizer;
use App\Game\Economy;
use App\Game\Espionage;
use App\Game\FleetMission;
use App\Game\FleetService;
use App\Game\FusionService;
use App\Game\GameException;
use App\Game\Inbox;
use App\Game\Navigation;
use App\Game\PlanetService;
use App\Game\PlanetUpdater;
use App\Game\Radar;
use App\Game\Resources;
use App\Game\TradeService;
use App\Repository\FleetRepository;
use App\Repository\PlanetRepository;
use App\Repository\TradePostRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Fleets abroad: an alliance mate's or bond partner's planet pays their departures; a planet with
 * own fleets bound to it can't be abandoned, and nobody gets a free ride from an abandoned one;
 * loot is stored only when the fleet lands at home.
 */
final class GuestFleetTest extends TestCase
{
    private \DateTimeImmutable $now;
    /** @var array<string, Planet> */
    private array $planets = [];
    /** @var list<Fleet> */
    private array $idle = [];
    /** @var list<Planet> */
    private array $abandoned = [];
    /** @var list<object> */
    private array $persisted = [];
    private User $guest;
    private User $host;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-18 12:00');
        $this->guest = $this->user('gast', 1);
        $this->host = $this->user('gastgeber', 2);
        $this->place(new Planet($this->guest, 'Heim', 1, 10, 1, $this->now))->markHomeworld();
        $this->place(new Planet($this->host, 'Gastplanet', 1, 20, 3, $this->now))->setResources(new Resources(hydrogen: 100000, food: 100000, water: 100000));
    }

    public function testAnAlliesPlanetPaysTheDeparture(): void
    {
        $this->ally();
        $fleet = new Fleet($this->guest, 'Besuch', 1, 20, 3, ['falke' => 10]);

        $this->service()->sendFleet($fleet, $this->guest, 1, 10, 1, FleetMission::Station, new Resources());

        self::assertTrue($fleet->isMoving());
        self::assertLessThan(100000, $this->planets['1:20:3']->getResources()->hydrogen, 'the host paid the fuel');
        self::assertTrue($fleet->getCargo()->isEmpty());
        self::assertCount(1, array_filter($this->messages(), fn (Message $m): bool => $m->getRecipient() === $this->host), 'the host is told');
    }

    public function testWithoutAllianceOrBondTheFleetPaysFromItsCargo(): void
    {
        $fleet = new Fleet($this->guest, 'Besuch', 1, 20, 3, ['falke' => 10]);

        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Die Ladung der Flotte reicht nicht');
        $this->service()->sendFleet($fleet, $this->guest, 1, 10, 1, FleetMission::Station, new Resources());
    }

    public function testAnAbandonedPlanetBecomesOwnerlessWithItsStorage(): void
    {
        $colony = $this->place(new Planet($this->guest, 'Kolonie', 1, 30, 2, $this->now));
        $colony->setResources(new Resources(iron: 7000, hydrogen: 900));
        $colony->setAbandonAt($this->now->modify('-1 minute'));
        $nobody = $this->user('Herrenlos', 99);
        $nobody->markSystem();

        self::assertTrue($this->planetService([])->giveUp($colony, $nobody));

        self::assertSame($nobody, $colony->getOwner());
        self::assertSame(7000.0, $colony->getResources()->iron, 'the storage stays');
        self::assertFalse($this->guest->getPlanets()->contains($colony), 'the slot is free for the player again');
    }

    public function testFleetsAtAnOwnerlessPlanetRelocateFromItsStorageButNeverAttack(): void
    {
        $nobody = $this->user('Herrenlos', 99);
        $nobody->markSystem();
        $wreck = $this->place(new Planet($nobody, 'Verlassen', 1, 30, 2, $this->now));
        $wreck->setResources(new Resources(hydrogen: 100000, food: 100000, water: 100000));
        $this->place(new Planet($this->host, 'Ziel', 1, 31, 4, $this->now));

        $attacker = new Fleet($this->guest, 'Sturm', 1, 30, 2, ['falke' => 10]);
        try {
            $this->service()->sendFleet($attacker, $this->guest, 1, 31, 4, FleetMission::Attack, new Resources());
            self::fail('no attack from an ownerless planet');
        } catch (GameException $e) {
            self::assertStringContainsString('herrenlos', $e->getMessage());
        }

        $mover = new Fleet($this->guest, 'Umzug', 1, 30, 2, ['falke' => 10]);
        $this->service()->sendFleet($mover, $this->guest, 1, 10, 1, FleetMission::Station, new Resources());
        self::assertTrue($mover->isMoving());
        self::assertLessThan(100000, $wreck->getResources()->hydrogen, 'the ownerless planet paid the relocation');
    }

    public function testLootIsStoredWhenTheFleetLandsAtHome(): void
    {
        $fleet = new Fleet($this->guest, 'Sturm', 1, 10, 1, ['falke' => 10, 'salvager' => 2]);
        $fleet->depart(FleetMission::Attack, 1, 20, 3, $this->now->modify('-2 hours'), 3600);
        $fleet->setCargo(new Resources(iron: 5000));
        $fleet->startReturn();

        (new \ReflectionMethod(FleetService::class, 'land'))->invoke($this->service(), $fleet);

        self::assertSame(5000.0, $this->planets['1:10:1']->getResources()->iron);
        self::assertTrue($fleet->getCargo()->isEmpty());
    }

    public function testAPlanetWithOwnFleetsBoundToItCannotBeAbandoned(): void
    {
        $colony = $this->place(new Planet($this->guest, 'Kolonie', 1, 30, 2, $this->now));
        $dry = new Fleet($this->guest, 'Trocken', 1, 30, 2, ['falke' => 5]);
        $raider = new Fleet($this->guest, 'Räuber', 1, 30, 2, ['falke' => 5]);
        $raider->depart(FleetMission::Attack, 1, 31, 4, $this->now, 600);
        $elsewhere = new Fleet($this->guest, 'Unterwegs', 1, 30, 2, ['falke' => 5]);
        $elsewhere->depart(FleetMission::Station, 1, 10, 1, $this->now, 600);
        $arriving = new Fleet($this->guest, 'Ankunft', 1, 10, 1, ['falke' => 5]);
        $arriving->depart(FleetMission::Station, 1, 30, 2, $this->now, 600);
        $delivery = new Fleet($this->guest, null, 1, 10, 1, ['freighter' => 5]);
        $delivery->depart(FleetMission::TransportStation, 1, 30, 2, $this->now, 600);

        foreach ([[[$dry], true], [[$raider], true], [[$arriving], true], [[$delivery], true], [[$elsewhere], false]] as [$fleets, $refused]) {
            $service = $this->planetService($fleets);
            try {
                $service->abandon($colony, $this->guest);
                self::assertFalse($refused, ($fleets[0]->getName() ?? 'Lieferung').' should block abandoning');
                $colony->setAbandonAt(null);
            } catch (GameException $e) {
                self::assertTrue($refused, ($fleets[0]->getName() ?? 'Lieferung').' should not block abandoning');
                self::assertStringContainsString('Hol sie zuerst weg', $e->getMessage());
            }
        }
    }

    public function testFleetsMayStillBeStationedAtAPlanetBeingAbandoned(): void
    {
        $colony = $this->place(new Planet($this->guest, 'Kolonie', 1, 30, 2, $this->now));
        $colony->setAbandonAt($this->now->modify('+1 day'));
        $this->planets['1:10:1']->setResources(new Resources(hydrogen: 100000, food: 100000, water: 100000));
        $fleet = new Fleet($this->guest, 'Hin', 1, 10, 1, ['falke' => 5]);

        $this->service()->sendFleet($fleet, $this->guest, 1, 30, 2, FleetMission::Station, new Resources());

        self::assertTrue($fleet->isMoving());
    }

    /** @param list<Fleet> $fleets */
    private function planetService(array $fleets): PlanetService
    {
        $clock = new MockClock($this->now);
        $em = $this->createStub(EntityManagerInterface::class);
        $repo = $this->createStub(FleetRepository::class);
        $repo->method('findOwnTouchingSystem')->willReturn($fleets);
        $economy = new Economy(1.0);

        return new PlanetService($em, new LockFactory(new InMemoryStore()), $clock, new PlanetUpdater($economy), $economy, $repo);
    }

    public function testAColonyShipSettlesAnOwnerlessPlanetWithItsQueues(): void
    {
        $this->guest->setResearchLevel(\App\Game\ResearchType::Colonization, 1);
        $nobody = $this->user('Herrenlos', 99);
        $nobody->markSystem();
        $old = $this->place(new Planet($this->host, 'Altbau', 1, 30, 2, $this->now));
        $old->setResources(new Resources(iron: 3000));
        $old->addBuildingOrder(new \App\Entity\BuildingOrder($old, \App\Game\BuildingType::IronMine, 2, new Resources(iron: 100), 1));
        $old->leaveToNobody($nobody, false, keepQueues: true);
        self::assertCount(1, $old->getBuildingQueue(), 'an abandoned planet keeps its queue');

        $ship = new Fleet($this->guest, null, 1, 10, 1, ['colony_ship' => 1]);
        $ship->depart(FleetMission::Colonize, 1, 30, 2, $this->now->modify('-1 hour'), 60);
        (new \ReflectionMethod(FleetService::class, 'arrive'))->invoke($this->service(), $ship);

        self::assertSame($this->guest, $old->getOwner(), 'the colony ship took it over');
        self::assertCount(1, $old->getBuildingQueue(), 'with its building queue');
        self::assertGreaterThanOrEqual(3000 + 500, $old->getResources()->iron, 'storage plus the ship\'s supplies');
    }

    public function testOwnerlessPlanetsWithShipsOrDefenseMustBeAttackedFirst(): void
    {
        $this->guest->setResearchLevel(\App\Game\ResearchType::Colonization, 1);
        $nobody = $this->user('Herrenlos', 99);
        $nobody->markSystem();
        $guarded = $this->place(new Planet($nobody, 'Bewacht', 1, 30, 2, $this->now));
        $guarded->addShips(\App\Game\ShipType::Falke, 3);
        $validate = new \ReflectionMethod(FleetService::class, 'validateMission');

        $this->expectException(GameException::class);
        $this->expectExceptionMessage('herrenlos, aber dort stehen noch Schiffe');
        $validate->invoke($this->service(), FleetMission::Colonize, $this->guest, $guarded, ['colony_ship' => 1], false, null, []);
    }

    public function testAFleetSentToALowerSlotOfAFusionWaitsAtItsTop(): void
    {
        $top = $this->place(new Planet($this->guest, 'Oben', 1, 50, 3, $this->now));
        $lower = new Planet($this->guest, 'Unten', 1, 50, 4, $this->now);
        \App\Game\Fusion::merge($top, [$lower]);
        $this->guest->getPlanets()->removeElement($lower);
        $this->planets['1:10:1']->setResources(new Resources(hydrogen: 100000, food: 100000, water: 100000));
        $fleet = new Fleet($this->guest, 'Wache', 1, 10, 1, ['falke' => 5]);

        $this->service()->sendFleet($fleet, $this->guest, 1, 50, 4, FleetMission::Station, new Resources());

        self::assertSame(3, $fleet->getTargetPosition(), 'the fusion is one planet: its top slot');
    }

    public function testAFleetOrderIsLoggedWithThePlayerItConcerns(): void
    {
        $this->ally();
        $fleet = new Fleet($this->guest, 'Besuch', 1, 20, 3, ['falke' => 10]);
        $this->place(new Planet($this->host, 'Nachbar', 1, 21, 1, $this->now));

        $this->service()->sendFleet($fleet, $this->guest, 1, 21, 1, FleetMission::Transport, new Resources());

        $entries = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof \App\Entity\ActionLogEntry));
        self::assertCount(1, $entries);
        self::assertSame($this->guest, $entries[0]->getActor());
        self::assertSame($this->host, $entries[0]->getOther(), 'the owner of the target planet');
        self::assertStringContainsString('Besuch: Transport von [1:20:3] nach [1:21:1] (Nachbar von gastgeber) · Schiffe: 10 Falke', $entries[0]->getText());
    }

    public function testAFleetOfTheGroupStartingAMomentLaterStillJoinsTheLead(): void
    {
        $this->place(new Planet($this->guest, 'Ziel', 1, 21, 1, $this->now));
        $this->planets['1:10:1']->setResources(new Resources(hydrogen: 100000, food: 100000, water: 100000));
        $navigation = new Navigation(new Economy(1.0));
        $seconds = $navigation->flightSeconds($navigation->distance(1, 10, 1, 1, 21, 1), $navigation->slowestSpeed(['falke' => 5], $this->guest));
        // The leading fleet of the group started two seconds earlier, at the same speed.
        $lead = new Fleet($this->guest, 'HR_01', 1, 10, 1, ['falke' => 5]);
        $lead->depart(FleetMission::TransportStation, 1, 21, 1, $this->now->modify('-2 seconds'), $seconds);
        $second = new Fleet($this->guest, 'HR_02', 1, 10, 1, ['falke' => 5]);

        $this->service()->sendFleet($second, $this->guest, 1, 21, 1, FleetMission::TransportStation, new Resources(), lead: $lead);

        self::assertEquals($lead->getArriveAt(), $second->getArriveAt(), 'it joins and arrives together with the lead');
    }

    private function ally(): void
    {
        $alliance = new Alliance('Bund', 'BND', $this->guest->getLifeForm(), $this->now);
        (new \ReflectionProperty(Alliance::class, 'id'))->setValue($alliance, 9);
        $this->guest->joinAlliance($alliance, AllianceRank::Founder);
        $this->host->joinAlliance($alliance, AllianceRank::Member);
    }

    private function user(string $name, int $id): User
    {
        $user = new User($name, $this->now);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function place(Planet $planet): Planet
    {
        (new \ReflectionProperty(Planet::class, 'id'))->setValue($planet, \count($this->planets) + 1);
        $this->planets[$planet->getCoordinates()] = $planet;

        return $planet;
    }

    /** @return list<Message> */
    private function messages(): array
    {
        return array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Message));
    }

    private function service(): FleetService
    {
        $clock = new MockClock($this->now);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->createStub(Connection::class));
        $em->method('persist')->willReturnCallback(function (object $o): void { $this->persisted[] = $o; });
        $planets = $this->createStub(PlanetRepository::class);
        $planets->method('findAt')->willReturnCallback(fn (int $g, int $s, int $p): ?Planet => array_values(array_filter(
            $this->planets,
            static fn (Planet $planet): bool => $planet->getGalaxy() === $g && $planet->getSystem() === $s && $planet->covers($p),
        ))[0] ?? null);
        $planets->method('findAbandonedBefore')->willReturnCallback(fn (): array => $this->abandoned);
        $fleets = $this->createStub(FleetRepository::class);
        $fleets->method('findIdleAt')->willReturnCallback(fn (): array => $this->idle);
        $fleets->method('countJoining')->willReturn(0);
        $economy = new Economy(1.0);
        $locks = new LockFactory(new InMemoryStore());
        $none = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new FleetService(
            $em, new PlanetService($em, $locks, $clock, new PlanetUpdater($economy), $economy), $planets, $fleets,
            new Navigation($economy), $economy, $none(Colonizer::class), $none(FusionService::class), $this->createStub(TradePostRepository::class),
            $none(TradeService::class), $none(CombatService::class), $none(Radar::class), $none(Espionage::class), new Inbox($em, $clock), $locks, $clock,
            actionLog: new \App\Game\ActionLog($em, $clock),
        );
    }
}
