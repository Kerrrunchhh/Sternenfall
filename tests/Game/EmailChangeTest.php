<?php

namespace App\Tests\Game;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

/** A new e-mail address waits for its confirmation link; the confirmed one stays valid until then. */
class EmailChangeTest extends TestCase
{
    public function testTheOldAddressStaysUntilTheNewOneIsConfirmed(): void
    {
        $player = new User('spieler');
        $player->setEmail('alt@example.org');
        $player->verifyEmail(new \DateTimeImmutable('2026-11-01'));

        self::assertTrue($player->requestEmail(' Neu@Example.org '));
        self::assertSame('alt@example.org', $player->getEmail(), 'mails still go to the old address');
        self::assertTrue($player->isEmailVerified());
        self::assertSame('neu@example.org', $player->getPendingEmail());

        self::assertFalse($player->confirmEmail('falsch@example.org', new \DateTimeImmutable()), 'only the link of the waiting address');
        self::assertTrue($player->confirmEmail('neu@example.org', new \DateTimeImmutable()));
        self::assertSame('neu@example.org', $player->getEmail());
        self::assertNull($player->getPendingEmail());
        self::assertTrue($player->isEmailVerified());
    }

    public function testTheChangeCanBeUndoneAndAnOldLinkThenDoesNothing(): void
    {
        $player = new User('spieler');
        $player->setEmail('alt@example.org');
        $player->verifyEmail(new \DateTimeImmutable('2026-11-01'));
        $player->requestEmail('neu@example.org');

        $player->cancelEmailChange();

        self::assertNull($player->getPendingEmail());
        self::assertFalse($player->confirmEmail('neu@example.org', new \DateTimeImmutable()));
        self::assertSame('alt@example.org', $player->getEmail());
    }

    public function testWithoutAConfirmedAddressTheNewOneReplacesItAtOnce(): void
    {
        $player = new User('spieler');
        $player->setEmail('vertippt@example.org');

        self::assertFalse($player->requestEmail('richtig@example.org'), 'nothing confirmed to keep');
        self::assertSame('richtig@example.org', $player->getEmail());
        self::assertTrue($player->needsEmailConfirmation());
        self::assertTrue($player->confirmEmail('richtig@example.org', new \DateTimeImmutable()));
        self::assertTrue($player->isEmailVerified());
    }
}
