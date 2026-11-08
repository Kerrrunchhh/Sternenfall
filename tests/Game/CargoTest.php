<?php

namespace App\Tests\Game;

use App\Game\Economy;
use App\Game\Navigation;
use App\Game\ShipType;
use PHPUnit\Framework\TestCase;

class CargoTest extends TestCase
{
    public function testOnlyTradeShipsCarryCargo(): void
    {
        foreach (ShipType::cases() as $type) {
            $trade = \in_array($type, [ShipType::Freighter, ShipType::LargeFreighter], true);
            self::assertSame($trade, $type->carriesCargo(), $type->label());
            self::assertSame($trade, $type->cargo() > 0, $type->label());
        }
    }

    public function testOnlySalvagersHaveAHoldForDebris(): void
    {
        self::assertSame(20000, ShipType::Salvager->salvageCapacity());
        self::assertSame(0, ShipType::Salvager->cargo(), 'debris only, no other cargo');
        self::assertSame(0, ShipType::Freighter->salvageCapacity());
    }

    public function testFleetCapacityCountsTradeShipsOnly(): void
    {
        $navigation = new Navigation(new Economy(1.0));

        self::assertSame(0, $navigation->cargoCapacity(['leviathan' => 10, 'colony_ship' => 1, 'salvager' => 3]));
        self::assertSame(5000 + 2 * 25000, $navigation->cargoCapacity(['leviathan' => 10, 'freighter' => 1, 'large_freighter' => 2]));
    }
}
