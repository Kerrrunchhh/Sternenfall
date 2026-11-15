<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\MultiAccounts;
use PHPUnit\Framework\TestCase;

/** The multi account check leaves out accounts that no longer play. */
class MultiAccountsTest extends TestCase
{
    public function testDeactivatedAccountsAndPendingDeletionsAreLeftOut(): void
    {
        $player = new User('Spielerin');
        self::assertFalse(MultiAccounts::isGone($player));

        $player->requestDeletion(new \DateTimeImmutable('+48 hours'), false);
        self::assertTrue(MultiAccounts::isGone($player), 'deletion pending');

        $player->cancelDeletion();
        $player->deactivate(new \DateTimeImmutable());
        self::assertTrue(MultiAccounts::isGone($player), 'deactivated');
    }
}
