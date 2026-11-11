<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\Colonizer;
use App\Game\Economy;
use App\Game\PlanetType;
use App\Repository\PlanetRepository;
use App\Repository\TradePostRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** New players never start on a stone or desert world. */
final class HomePlanetTest extends TestCase
{
    public function testHomePlanetsAreNeverStoneOrDesert(): void
    {
        $colonizer = new Colonizer(
            $this->createStub(PlanetRepository::class),
            $this->createStub(TradePostRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new MockClock('2026-11-11'),
            new Economy(1.0),
        );

        $seen = [];
        for ($i = 0; $i < 500; ++$i) {
            $type = $colonizer->createHomePlanet(new User('neu'.$i))->getType();
            self::assertNotContains($type, [PlanetType::Stone, PlanetType::Desert], 'home planet #'.$i);
            $seen[$type->value] = true;
        }
        self::assertCount(3, $seen, 'blue, red and water worlds all occur');
    }

    public function testOnlyBlueRedAndWaterAreFriendly(): void
    {
        $friendly = array_values(array_filter(PlanetType::cases(), static fn (PlanetType $t): bool => $t->isFriendly()));

        self::assertSame([PlanetType::Blue, PlanetType::Red, PlanetType::Water], $friendly);
    }
}
