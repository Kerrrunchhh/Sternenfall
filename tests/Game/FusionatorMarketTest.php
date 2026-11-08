<?php

namespace App\Tests\Game;

use App\Entity\Alliance;
use App\Entity\Fleet;
use App\Entity\FusionatorOffer;
use App\Entity\Planet;
use App\Entity\User;
use App\Game\BuyerAccess;
use App\Game\Economy;
use App\Game\FleetMission;
use App\Game\FusionatorMarket;
use App\Game\GameException;
use App\Game\Inbox;
use App\Game\LifeForm;
use App\Game\Navigation;
use App\Game\PlanetService;
use App\Game\PlanetUpdater;
use App\Game\ShipType;
use App\Repository\AllianceRepository;
use App\Repository\PlanetRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class FusionatorMarketTest extends TestCase
{
    private \DateTimeImmutable $now;
    private User $trader;
    private User $buyer;
    private Planet $traderPlanet;
    private Planet $buyerHome;
    /** @var list<object> */
    private array $persisted = [];
    /** @var list<object> */
    private array $removed = [];

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01 12:00:00');
        $this->trader = new User('haendler', $this->now);
        $this->trader->changeLifeForm(LifeForm::Trader, $this->now);
        $this->buyer = new User('kaeufer', $this->now);
        $this->traderPlanet = new Planet($this->trader, 'Markt', 1, 1, 3, $this->now);
        $this->traderPlanet->addShips(ShipType::Fusionator, 10);
        $this->buyerHome = new Planet($this->buyer, 'Heim', 1, 5, 7, $this->now);
        $this->buyerHome->markHomeworld();
        $this->buyer->addCredits(1_000_000);
    }

    public function testAccessForAllOrRestrictedToLifeFormsAndAlliances(): void
    {
        $alliance = new Alliance('Orden', 'ORD', LifeForm::Guardian, $this->now);

        self::assertTrue(BuyerAccess::allows($this->trader, $this->buyer, [], []), 'unrestricted: everybody');
        self::assertFalse(BuyerAccess::allows($this->trader, $this->buyer, ['pirate'], [$alliance]));
        self::assertTrue(BuyerAccess::allows($this->trader, $this->buyer, ['settler'], []), 'buyer is a settler');
        self::assertTrue(BuyerAccess::allows($this->trader, $this->trader, ['pirate'], []), 'the seller always');

        $this->buyer->joinAlliance($alliance, \App\Game\AllianceRank::Member);
        self::assertTrue(BuyerAccess::allows($this->trader, $this->buyer, ['pirate'], [$alliance]), 'member of an allowed alliance');
    }

    public function testOfferTakesFusionatorsOutOfTheHangar(): void
    {
        $this->market()->offer($this->traderPlanet, 4, 50_000, [], []);

        self::assertSame(6, $this->traderPlanet->getShipCount(ShipType::Fusionator));
        $offer = $this->offers()[0];
        self::assertSame(4, $offer->getQuantity());
        self::assertSame(50_000.0, $offer->getPrice());
        self::assertSame('1:1:3', $offer->getCoordinates());
    }

    public function testOnlyTradersMayOfferAndNotMoreThanInTheHangar(): void
    {
        $this->expectException(GameException::class);
        $this->market()->offer($this->traderPlanet, 11, 1, [], []);
    }

    public function testSettlersCanNotOffer(): void
    {
        $settlerPlanet = new Planet($this->buyer, 'Werft', 1, 2, 2, $this->now);
        $settlerPlanet->addShips(ShipType::Fusionator, 1);

        $this->expectException(GameException::class);
        $this->market()->offer($settlerPlanet, 1, 1, [], []);
    }

    public function testBuyingPartOfAPackSendsAFleetToTheBuyersHomePlanet(): void
    {
        $offer = new FusionatorOffer($this->trader, $this->traderPlanet, 5, 100_000, [], [], $this->now);

        $this->market()->buy($offer, $this->buyer, 2);

        self::assertSame(3, $offer->getQuantity());
        self::assertSame(800_000.0, $this->buyer->getCredits());
        self::assertSame(200_000.0, $this->trader->getCredits());
        $fleet = $this->fleets()[0];
        self::assertSame($this->buyer, $fleet->getOwner());
        self::assertSame(2, $fleet->getShipCount(ShipType::Fusionator));
        self::assertSame('1:1:3', $fleet->getCoordinates(), 'leaves from where the offer was made');
        self::assertSame(FleetMission::Station, $fleet->getMission());
        self::assertSame('1:5:7', $fleet->getTargetCoordinates());
        self::assertGreaterThan($this->now, $fleet->getArriveAt(), 'normal flight time');
        self::assertTrue($fleet->getCargo()->isEmpty());
    }

    public function testBuyingTheWholePackRemovesTheOffer(): void
    {
        $offer = new FusionatorOffer($this->trader, $this->traderPlanet, 5, 100_000, [], [], $this->now);

        $this->market()->buy($offer, $this->buyer, 5);

        self::assertSame(0, $offer->getQuantity());
        self::assertContains($offer, $this->removed);
    }

    public function testNotEnoughCreditsBuysNothing(): void
    {
        $offer = new FusionatorOffer($this->trader, $this->traderPlanet, 5, 600_000, [], [], $this->now);

        try {
            $this->market()->buy($offer, $this->buyer, 2);
            self::fail('should not be affordable');
        } catch (GameException) {
        }
        self::assertSame(5, $offer->getQuantity());
        self::assertSame(1_000_000.0, $this->buyer->getCredits());
        self::assertSame([], $this->fleets());
    }

    public function testRestrictedOfferIsNotForOthers(): void
    {
        $offer = new FusionatorOffer($this->trader, $this->traderPlanet, 5, 1, [LifeForm::Pirate], [], $this->now);

        $this->expectException(GameException::class);
        $this->market()->buy($offer, $this->buyer, 1);
    }

    public function testWithdrawPutsTheRestBackIntoTheHangar(): void
    {
        $offer = new FusionatorOffer($this->trader, $this->traderPlanet, 4, 1, [], [], $this->now);
        $this->traderPlanet->addShips(ShipType::Fusionator, -4);

        $this->market()->withdraw($offer, $this->trader);

        self::assertSame(10, $this->traderPlanet->getShipCount(ShipType::Fusionator));
        self::assertContains($offer, $this->removed);
    }

    private function market(): FusionatorMarket
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });
        $em->method('remove')->willReturnCallback(function (object $o): void {
            $this->removed[] = $o;
        });
        $clock = new MockClock($this->now);
        $economy = new Economy(1.0);
        $planets = $this->createStub(PlanetRepository::class);
        $planets->method('findAt')->willReturnCallback(fn (int $g, int $s, int $p): ?Planet => '1:1:3' === "$g:$s:$p" ? $this->traderPlanet : null);
        $alliances = $this->createStub(AllianceRepository::class);
        $alliances->method('findBy')->willReturn([]);

        return new FusionatorMarket(
            $em,
            new PlanetService($em, new LockFactory(new InMemoryStore()), $clock, new PlanetUpdater($economy), $economy),
            $planets,
            $alliances,
            new Navigation($economy),
            $economy,
            new Inbox($em, $clock),
            new LockFactory(new InMemoryStore()),
            $clock,
        );
    }

    /** @return list<FusionatorOffer> */
    private function offers(): array
    {
        return array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof FusionatorOffer));
    }

    /** @return list<Fleet> */
    private function fleets(): array
    {
        return array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Fleet));
    }
}
