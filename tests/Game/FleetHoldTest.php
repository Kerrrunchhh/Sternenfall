<?php

namespace App\Tests\Game;

use App\Entity\Fleet;
use App\Entity\User;
use App\Game\ResourceType;
use PHPUnit\Framework\TestCase;

/** The overview shows a fleet's cargo, "empty" for an empty hold, and what a pickup is to load. */
final class FleetHoldTest extends TestCase
{
    public function testFreightersAndSalvagersHaveAHoldWarshipsDont(): void
    {
        $owner = new User('haendler', new \DateTimeImmutable('2026-11-19'));

        self::assertTrue((new Fleet($owner, 'HR03', 1, 1, 1, ['large_freighter' => 1]))->hasHold());
        self::assertTrue((new Fleet($owner, 'Berger', 1, 1, 1, ['falke' => 5, 'salvager' => 1]))->hasHold());
        self::assertFalse((new Fleet($owner, 'Sturm', 1, 1, 1, ['falke' => 5]))->hasHold());
    }

    public function testThePickupOrderReadsAsResources(): void
    {
        $fleet = new Fleet(new User('haendler', new \DateTimeImmutable('2026-11-19')), 'Abholer', 1, 1, 1, ['freighter' => 2]);
        self::assertTrue($fleet->getPickupGoods()->isEmpty());

        $fleet->setTradeOrder(['iron' => 2000, 'water' => 500]);

        self::assertSame(2000.0, $fleet->getPickupGoods()->get(ResourceType::Iron));
        self::assertSame(500.0, $fleet->getPickupGoods()->get(ResourceType::Water));
        self::assertSame(2500.0, $fleet->getPickupGoods()->total());
    }
}
