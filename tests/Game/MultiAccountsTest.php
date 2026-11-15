<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\MultiAccounts;
use PHPUnit\Framework\TestCase;

/** The multi account check leaves out accounts that no longer play, and admin accounts. */
class MultiAccountsTest extends TestCase
{
    public function testDeactivatedAccountsAndPendingDeletionsAreLeftOut(): void
    {
        $player = new User('Spielerin');
        self::assertFalse(MultiAccounts::isLeftOut($player));

        $player->requestDeletion(new \DateTimeImmutable('+48 hours'), false);
        self::assertTrue(MultiAccounts::isLeftOut($player), 'deletion pending');

        $player->cancelDeletion();
        $player->deactivate(new \DateTimeImmutable());
        self::assertTrue(MultiAccounts::isLeftOut($player), 'deactivated');
    }

    public function testAdminAccountsAreLeftOutModeratorsNot(): void
    {
        $admin = new User('Chefin');
        $admin->setRoles(['ROLE_ADMIN']);
        self::assertTrue(MultiAccounts::isLeftOut($admin));

        $moderator = new User('Aufpasser');
        $moderator->setRoles(['ROLE_MODERATOR']);
        self::assertFalse(MultiAccounts::isLeftOut($moderator));
    }
}
