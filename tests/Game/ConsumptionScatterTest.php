<?php

namespace App\Tests\Game;

use App\Game\Navigation;
use App\Game\Resources;
use PHPUnit\Framework\TestCase;

/** Fuel and provisions scatter by up to ±5 %, so a planet's loss doesn't give the target away. */
final class ConsumptionScatterTest extends TestCase
{
    public function testTheConsumptionVariesWithinFivePercent(): void
    {
        $computed = new Resources(food: 2000, water: 3000, hydrogen: 10000);
        $seen = [];
        for ($i = 0; $i < 200; ++$i) {
            $used = Navigation::vary($computed);
            foreach (['hydrogen' => 10000, 'food' => 2000, 'water' => 3000] as $type => $amount) {
                self::assertGreaterThanOrEqual(floor($amount * 0.95), $used->{$type});
                self::assertLessThanOrEqual(ceil($amount * 1.05), $used->{$type});
            }
            $seen[$used->hydrogen] = true;
        }
        self::assertGreaterThan(20, \count($seen), 'not the same amount every time');
        self::assertTrue(Navigation::vary(new Resources())->isEmpty(), 'nothing stays nothing');
    }
}
