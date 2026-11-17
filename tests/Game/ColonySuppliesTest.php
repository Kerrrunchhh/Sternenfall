<?php

namespace App\Tests\Game;

use App\Game\BuildingType;
use App\Game\Colonizer;
use App\Game\Economy;
use App\Game\ResourceType;
use PHPUnit\Framework\TestCase;

/** A fresh colony must be able to reach the electrolysis on its own: no hydrogen without it. */
final class ColonySuppliesTest extends TestCase
{
    public function testTheSuppliesPayTheCommandCenterUpToTheElectrolysis(): void
    {
        $economy = new Economy(1.0);
        $needed = 0.0;
        foreach (BuildingType::Electrolysis->requirements() as $requirement) {
            for ($level = 1; $level <= $requirement->level; ++$level) {
                $needed += $economy->buildCost($requirement->subject, $level)->get(ResourceType::Hydrogen);
            }
        }
        $needed += $economy->buildCost(BuildingType::Electrolysis, 1)->get(ResourceType::Hydrogen);

        self::assertGreaterThan(0, $needed, 'the command center costs hydrogen');
        self::assertGreaterThanOrEqual($needed, Colonizer::supplies()->get(ResourceType::Hydrogen));
    }
}
