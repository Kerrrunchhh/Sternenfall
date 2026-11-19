<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\AccountDeletion;
use App\Game\ProfileService;
use PHPUnit\Framework\TestCase;

/** The ownerless account's profile must not hand out a list of every abandoned planet to settle. */
final class OwnerlessProfileTest extends TestCase
{
    public function testTheOwnerlessAccountListsNoPlanetsOthersDo(): void
    {
        $now = new \DateTimeImmutable('2026-11-19');
        $nobody = new User(AccountDeletion::NOBODY, $now);
        $nobody->markSystem();
        new Planet($nobody, 'Verlassen', 3, 146, 7, $now);
        $player = new User('siedlerin', $now);
        new Planet($player, 'Heim', 3, 146, 8, $now);

        self::assertTrue(ProfileService::hidesPlanets($nobody));
        self::assertSame([], ProfileService::listedPlanets($nobody, false));
        self::assertFalse(ProfileService::hidesPlanets($player));
        self::assertCount(1, ProfileService::listedPlanets($player, false));
    }
}
