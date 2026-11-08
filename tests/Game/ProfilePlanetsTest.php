<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\ProfileService;
use PHPUnit\Framework\TestCase;

class ProfilePlanetsTest extends TestCase
{
    public function testHiddenPlanetsAreLeftOutForOthersOnly(): void
    {
        $now = new \DateTimeImmutable('2026-01-01');
        $player = new User('stratege', $now);
        $home = new Planet($player, 'Heim', 2, 5, 3, $now);
        $combat = new Planet($player, 'Kampfkolonie', 1, 9, 9, $now);
        $combat->setHiddenInProfile(true);

        self::assertSame([$home], ProfileService::listedPlanets($player, own: false));
        self::assertSame([$combat, $home], ProfileService::listedPlanets($player, own: true), 'sorted by coordinates');
        self::assertSame([$combat], ProfileService::hiddenPlanets($player));
    }

    public function testAConqueredPlanetIsVisibleAgain(): void
    {
        $now = new \DateTimeImmutable('2026-01-01');
        $planet = new Planet(new User('alt', $now), 'Versteck', 1, 1, 1, $now);
        $planet->setHiddenInProfile(true);

        $planet->conquer(new User('neu', $now), $now);

        self::assertFalse($planet->isHiddenInProfile(), 'the new owner decides for himself');
    }
}
