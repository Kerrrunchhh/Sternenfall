<?php

namespace App\Tests\Game;

use App\Entity\Alliance;
use App\Entity\Contract;
use App\Entity\Planet;
use App\Entity\TransportRequest;
use App\Entity\User;
use App\Game\ContractService;
use App\Game\GameException;
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
        self::assertSame(100, TransportRequests::suggestedOffer(1000));
        self::assertSame(1, TransportRequests::suggestedOffer(3));

        foreach ([
            [$stranger, new Resources(iron: 100), 10, 'eigenen Planeten'],
            [$beginner, new Resources(), 10, 'welche Rohstoffe'],
            [$beginner, new Resources(iron: TransportRequests::maxLoad() + 1), 10, 'Frachterladung'],
            [$beginner, new Resources(iron: 100), 0, 'mindestens 1 Credit'],
        ] as [$who, $goods, $offer, $error]) {
            try {
                $service->create($who, $home, $goods, $offer);
                self::fail('expected: '.$error);
            } catch (GameException $e) {
                self::assertStringContainsString($error, $e->getMessage());
            }
        }

        $request = $service->create($beginner, $home, new Resources(iron: 500, water: 200), 70);
        self::assertSame(TransportRequest::OPEN, $request->getStatus());
        self::assertSame('1:1:1', $request->getCoordinates());
        self::assertEqualsWithDelta(700, $request->getGoods()->total(), 0.001);

        $this->expectException(GameException::class);
        $this->transportRequests(taken: 0)->create($beginner, $home, new Resources(iron: 100), 10_000);
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

    private function inbox(): Inbox
    {
        return new Inbox($this->createStub(EntityManagerInterface::class), new MockClock($this->now));
    }
}
