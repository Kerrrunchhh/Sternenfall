<?php

namespace App\Tests\Game;

use App\Game\PlanetNames;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class PlanetNamesTest extends TestCase
{
    public function testColoniesGetVariedNamesThatFitAndAvoidTakenOnes(): void
    {
        $random = new Randomizer(new Mt19937(42));
        $names = [];
        for ($i = 0; $i < 50; ++$i) {
            $name = PlanetNames::random($names, $random);
            self::assertNotContains($name, $names, 'a player never gets the same name twice');
            self::assertLessThanOrEqual(30, mb_strlen($name), 'fits the planet name limit');
            self::assertNotSame('Kolonie', $name);
            $names[] = $name;
        }
    }
}
