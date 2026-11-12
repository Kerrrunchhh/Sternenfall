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
}
