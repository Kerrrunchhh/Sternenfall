<?php

namespace App\Tests\Game;

use App\Entity\Fleet;
use App\Entity\User;
use App\Game\LootOrder;
use App\Game\ResourceType;
use App\Game\Resources;
use PHPUnit\Framework\TestCase;

/** Plunder evenly, or the resources in the order the attacker chose. */
final class LootOrderTest extends TestCase
{
    public function testEvenlyTakesTheSameShareOfEveryResource(): void
    {
        $loot = LootOrder::take(new Resources(iron: 1000, titanium: 1000), 1000, null);

        self::assertSame(500.0, $loot->iron);
        self::assertSame(500.0, $loot->titanium);
    }

    public function testAnOrderTakesTheFirstResourceAsFarAsItGoesThenTheNext(): void
    {
        $order = [ResourceType::Hydrogen, ResourceType::Titanium, ResourceType::Iron, ResourceType::Food, ResourceType::Water];

        $loot = LootOrder::take(new Resources(iron: 5000, titanium: 800, hydrogen: 300.7), 1500, $order);

        self::assertSame(300.0, $loot->hydrogen);
        self::assertSame(800.0, $loot->titanium);
        self::assertSame(400.0, $loot->iron);
        self::assertSame(1500.0, $loot->total());
    }

    public function testOnlyACompleteOrderCounts(): void
    {
        self::assertNull(LootOrder::parse(['iron', 'titanium']), 'incomplete: evenly');
        self::assertNull(LootOrder::parse(['iron', 'iron', 'titanium', 'food', 'water']), 'doubled: evenly');
        self::assertSame(ResourceType::Water, LootOrder::parse(['water', 'iron', 'titanium', 'food', 'hydrogen', 'gold'])[0]);
    }

    public function testFleetAndPlayerKeepTheOrder(): void
    {
        $player = new User('pirat', new \DateTimeImmutable('2026-11-20'));
        $fleet = new Fleet($player, 'Sturm', 1, 1, 1, ['falke' => 5]);
        $order = LootOrder::parse(['titanium', 'iron', 'hydrogen', 'food', 'water']);

        $fleet->setLootOrder($order);
        $player->setLootOrder($order);

        self::assertSame($order, $fleet->getLootOrder());
        self::assertSame(ResourceType::Titanium, $player->getLootOrderOrDefault()[0]);
        $player->setLootOrder(null);
        self::assertNull($player->getLootOrder());
        self::assertSame(ResourceType::cases(), $player->getLootOrderOrDefault(), 'the form shows the normal order');
    }
}
