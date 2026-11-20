<?php

namespace App\Tests\Game;

use App\Entity\Alliance;
use App\Entity\Contract;
use App\Entity\Planet;
use App\Entity\Fleet;
use App\Entity\TransportRequest;
use App\Entity\User;
use App\Game\ContractService;
use App\Game\GameException;
use App\Game\FleetMission;
use App\Game\Inbox;
use App\Game\LifeForm;
use App\Game\Resources;
use App\Game\ShipType;
use App\Game\TransportRequests;
use App\Repository\ContractRepository;
use App\Repository\PlanetRepository;
use App\Repository\TransportRequestRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Alliance tax, contracts and transport requests. */
final class MarketplaceTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-10 12:00');
    }

    public function testAllianceTaxIsCappedAndCollectedOnce(): void
    {
        $alliance = new Alliance('Bund', 'BND', LifeForm::Settler, $this->now);
        $alliance->setTaxRate(25);
        self::assertSame(Alliance::MAX_TAX, $alliance->getTaxRate());
        $alliance->setTaxRate(5);

        self::assertEqualsWithDelta(50.0, $alliance->collectTax(1000), 0.001);
        $alliance->collectTax(200);
        self::assertEqualsWithDelta(60.0, $alliance->takePendingTax(), 0.001);
        self::assertSame(0.0, $alliance->takePendingTax(), 'taken only once');
    }

    public function testProtectionContractLetsOnlyTheContractorStayWhileRunning(): void
    {
        $client = new User('kunde', $this->now);
        $guard = new User('wache', $this->now);
        $other = new User('fremd', $this->now);
        $planet = new Planet($client, 'Heim', 2, 5, 7, $this->now);
        $contract = new Contract($client, Contract::PROTECTION, $planet, 1000, 7, '', $this->now);
        $contract->assign($guard, $this->now);

        $repo = $this->createStub(ContractRepository::class);
        $repo->method('findRunningIn')->willReturn([$contract]);
        $service = new ContractService($this->createStub(EntityManagerInterface::class), $repo, $this->createStub(PlanetRepository::class), $this->inbox(), new MockClock($this->now));

        self::assertTrue($service->protects($guard, $planet));
        self::assertFalse($service->protects($other, $planet));
        self::assertEquals($this->now->modify('+7 days'), $contract->getEndsAt());
    }

    public function testTransportRequestNeedsOwnPlanetAtMostOneFreighterLoadAndCredits(): void
    {
        $beginner = new User('neu', $this->now);
        $stranger = new User('anderer', $this->now);
        $home = new Planet($beginner, 'Heim', 1, 1, 1, $this->now);
        $service = $this->transportRequests(taken: 1);

        self::assertSame(ShipType::Freighter->cargo(), TransportRequests::maxLoad());
        self::assertSame(100, TransportRequests::price(1000));
        self::assertSame(1, TransportRequests::price(3));

        foreach ([
            [$stranger, new Resources(iron: 100), 'eigenen Planeten'],
            [$beginner, new Resources(), 'welche Rohstoffe'],
            [$beginner, new Resources(iron: TransportRequests::maxLoad() + 1), 'Frachterladung'],
        ] as [$who, $goods, $error]) {
            try {
                $service->create($who, $home, $goods);
                self::fail('expected: '.$error);
            } catch (GameException $e) {
                self::assertStringContainsString($error, $e->getMessage());
            }
        }

        $request = $service->create($beginner, $home, new Resources(iron: 500, water: 205));
        self::assertSame(TransportRequest::OPEN, $request->getStatus());
        self::assertEqualsWithDelta(71, $request->getOffer(), 0.001, 'fixed price: 705 resources = 71 credits');
        self::assertSame('1:1:1', $request->getCoordinates());
        self::assertEqualsWithDelta(705, $request->getGoods()->total(), 0.001);

        $this->expectException(GameException::class);
        $this->transportRequests(taken: 0)->create($beginner, $home, new Resources(iron: 100));
    }

    public function testAtMostThreeRequestsAtTheSameTime(): void
    {
        $beginner = new User('neu', $this->now);
        $home = new Planet($beginner, 'Heim', 1, 1, 1, $this->now);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $requests = $this->createStub(TransportRequestRepository::class);
        $requests->method('countActive')->willReturn(TransportRequests::MAX_ACTIVE);
        $service = new TransportRequests($em, $requests, $this->inbox(), new MockClock($this->now));

        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Transportgesuche laufen');
        $service->create($beginner, $home, new Resources(iron: 100));
    }

    public function testRequestsAreAStartingHelpForTheFirstFourteenDays(): void
    {
        $beginner = new User('neu', $this->now->modify('-13 days'));
        (new \ReflectionProperty(User::class, 'id'))->setValue($beginner, 5);
        $home = new Planet($beginner, 'Heim', 1, 1, 1, $this->now);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $service = new TransportRequests($em, $this->createStub(TransportRequestRepository::class), $this->inbox(), new MockClock($this->now));

        self::assertEquals($this->now->modify('+1 day'), TransportRequests::openUntil($beginner));
        $service->create($beginner, $home, new Resources(iron: 100));

        $veteran = new User('alt', $this->now->modify('-15 days'));
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('ersten {days} Tagen');
        $service->create($veteran, new Planet($veteran, 'Heim', 1, 1, 2, $this->now), new Resources(iron: 100));
    }

    public function testFinishedRequestsAreDeletedThreeDaysAfterTheyClosed(): void
    {
        $beginner = new User('neu', $this->now);
        $home = new Planet($beginner, 'Heim', 1, 1, 1, $this->now);
        $request = new TransportRequest($beginner, $home, new Resources(iron: 100), 10, $this->now->modify('-5 days'));
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $requests = $this->createMock(TransportRequestRepository::class);
        $requests->expects(self::once())->method('deleteClosedBefore')
            ->with(self::equalTo($this->now->modify('-3 days')))->willReturn(2);
        $service = new TransportRequests($em, $requests, $this->inbox(), new MockClock($this->now));

        $service->cancel($request, $beginner);
        self::assertEquals($this->now, $request->getClosedAt(), 'the three days count from closing, not from posting');
        self::assertSame(2, $service->purgeClosed());
    }

    public function testCancelOnlyWhileOpen(): void
    {
        $beginner = new User('neu', $this->now);
        $trader = new User('haendler', $this->now);
        $home = new Planet($beginner, 'Heim', 1, 1, 1, $this->now);
        $request = new TransportRequest($beginner, $home, new Resources(iron: 100), 10, $this->now);
        $service = $this->transportRequests(taken: 1);

        $this->expectException(GameException::class);
        $service->cancel($request, $trader);
    }

    private function transportRequests(int $taken): TransportRequests
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturn($taken);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new TransportRequests($em, $this->createStub(TransportRequestRepository::class), $this->inbox(), new MockClock($this->now));
    }

    public function testARecalledDeliveryFreesTheRequestAtOnce(): void
    {
        $beginner = new User('neu', $this->now);
        $home = new Planet($beginner, 'Heim', 12, 46, 11, $this->now);
        $trader = new User('haendler', $this->now);
        $fleet = new Fleet($trader, '02:HR:02', 20, 430, 6, ['freighter' => 1]);
        $fleet->depart(FleetMission::Transport, 12, 46, 11, $this->now, 3600);
        $request = new TransportRequest($beginner, $home, new Resources(titanium: 5000), 500, $this->now);
        $request->assign($trader, $fleet);
        $requests = $this->createStub(TransportRequestRepository::class);
        $requests->method('findAssignedToFleets')->willReturn([$request]);
        $service = new TransportRequests($this->createStub(EntityManagerInterface::class), $requests, $this->inbox(), new MockClock($this->now));

        $service->reopenOrphans();
        self::assertSame(TransportRequest::ASSIGNED, $request->getStatus(), 'on its way: stays assigned');

        $fleet->recall($this->now->modify('+10 minutes'));
        $service->reopenOrphans();
        self::assertSame(TransportRequest::OPEN, $request->getStatus(), 'recalled: open again for others and the guild');
        self::assertNull($request->getFleet());
    }

    private function inbox(): Inbox
    {
        return new Inbox($this->createStub(EntityManagerInterface::class), new MockClock($this->now));
    }
}
