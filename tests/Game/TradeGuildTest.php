<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\TradeGuild;
use PHPUnit\Framework\TestCase;

/** The trade guild's fixed prices: a bit worse than the value, both ways. */
final class TradeGuildTest extends TestCase
{
    public function testItSellsDearerAndBuysCheaperThanTheValue(): void
    {
        $sell = TradeGuild::sellPrices();
        $buy = TradeGuild::buyPrices();

        self::assertSame(['iron', 'titanium', 'food', 'water', 'hydrogen'], array_keys($sell), 'every resource');
        foreach (TradeGuild::VALUES as $resource => $value) {
            self::assertGreaterThan($value, $sell[$resource], $resource.': sells above value');
            self::assertLessThan($value, $buy[$resource], $resource.': buys below value');
        }
        self::assertSame(2.5, $sell['iron']);
        self::assertSame(1.5, $buy['iron']);
        self::assertSame(250000.0, TradeGuild::fusionatorPrice());
    }

    public function testGuildDeliveriesFlyAtMostFourHours(): void
    {
        $navigation = new \App\Game\Navigation(new \App\Game\Economy(1.0));
        $speed = \App\Game\ShipType::LargeFreighter->spec()->speed;
        $flight = static fn (int $system): int => TradeGuild::deliverySeconds($navigation->flightSeconds($navigation->distance(4, TradeGuild::SYSTEM, 1, 4, $system, 9), $speed), 1.0);

        self::assertSame(9225, $flight(249), 'next to the central exchange: about 2,5 hours as before');
        self::assertSame(4 * 3600, $flight(35), 'far away: cut to 4 hours instead of 16');
        self::assertSame(4 * 3600, $flight(500));
        self::assertSame(2 * 3600, TradeGuild::deliverySeconds(65025, 2.0), 'twice the game speed, half the time');
    }

    public function testOnlyTheSystemAccountNamedLikeTheGuildIsTheGuild(): void
    {
        $guild = new User(TradeGuild::NAME);
        $guild->markSystem();
        $impostor = new User(TradeGuild::NAME);

        $service = (new \ReflectionClass(TradeGuild::class))->newInstanceWithoutConstructor();
        self::assertTrue($service->isGuild($guild));
        self::assertFalse($service->isGuild($impostor), 'a player can not pass for the guild');
    }

    public function testTheGuildCanNotBeAttackedAndSpansTheWholeCentralSystem(): void
    {
        $now = new \DateTimeImmutable('2026-11-12 10:00:00');
        $guild = new User(TradeGuild::NAME, $now);
        $guild->markSystem();
        $player = new User('pirat', $now);
        $em = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
        $clock = new \Symfony\Component\Clock\MockClock($now);
        $diplomacy = new \App\Game\Diplomacy($em, new \App\Game\Inbox($em, $clock), new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore()), $clock);

        self::assertFalse($diplomacy->mayAttack($player, $guild), 'the guild is no target');
        self::assertTrue($diplomacy->mayAttack($player, new User('opfer', $now)), 'other players still are');

        $fusion = new \App\Entity\Planet($guild, TradeGuild::POST_NAME, 3, TradeGuild::SYSTEM, 1, $now);
        $fusion->spanTo(36, TradeGuild::POST_NAME);
        self::assertSame(36, $fusion->getFusionSize());
        self::assertTrue($fusion->covers(36));
        self::assertFalse($fusion->covers(37));
    }

    public function testAttackingTheGuildIsRefusedFirstAndAFleetInFlightSaysWhereItGoes(): void
    {
        $now = new \DateTimeImmutable('2026-11-12 10:00:00');
        $guild = new User(TradeGuild::NAME, $now);
        $guild->markSystem();
        $fusion = new \App\Entity\Planet($guild, TradeGuild::POST_NAME, 3, TradeGuild::SYSTEM, 1, $now);
        $fusion->spanTo(36, TradeGuild::POST_NAME);
        $planets = $this->createStub(\App\Repository\PlanetRepository::class);
        $planets->method('findAt')->willReturnCallback(static fn (int $g, int $s, int $p): ?\App\Entity\Planet => TradeGuild::SYSTEM === $s && $fusion->covers($p) ? $fusion : null);
        $posts = $this->createStub(\App\Repository\TradePostRepository::class);

        $service = (new \ReflectionClass(\App\Game\FleetService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\App\Game\FleetService::class, 'planetRepository'))->setValue($service, $planets);
        (new \ReflectionProperty(\App\Game\FleetService::class, 'tradePosts'))->setValue($service, $posts);
        $refuse = new \ReflectionMethod(\App\Game\FleetService::class, 'refuseGuildAttack');

        try {
            $refuse->invoke($service, \App\Game\FleetMission::Attack, 3, TradeGuild::SYSTEM, 20);
            self::fail('the guild fusion can not be attacked, on any of its slots');
        } catch (\App\Game\GameException $e) {
            self::assertStringContainsString('Handelsgilde', $e->getMessage());
        }
        $refuse->invoke($service, \App\Game\FleetMission::Attack, 3, 144, 1);
        $refuse->invoke($service, \App\Game\FleetMission::Trade, 3, TradeGuild::SYSTEM, 1);

        $fleet = new \App\Entity\Fleet(new User('pilot', $now), 'handel01', 3, 144, 6, ['large_freighter' => 1]);
        $fleet->depart(\App\Game\FleetMission::Station, 3, 144, 1, $now, 600);
        $text = (new \ReflectionMethod(\App\Game\FleetService::class, 'alreadyUnderway'))->invoke($service, $fleet)->trans(new \Symfony\Component\Translation\IdentityTranslator());
        self::assertStringContainsString('handel01', $text);
        self::assertStringContainsString('[3:144:1]', $text, 'says where the fleet is heading');
    }
}
