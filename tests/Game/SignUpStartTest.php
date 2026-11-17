<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\Economy;
use App\Game\LifeForm;
use PHPUnit\Framework\TestCase;

/** The account starts with the confirmed address: starter bonus and life form cooldown count from then. */
final class SignUpStartTest extends TestCase
{
    public function testTheStarterBonusRunsFromTheConfirmation(): void
    {
        $economy = new Economy(1.0, 1.0, true);
        $player = new User('spaet', new \DateTimeImmutable('2026-11-17 08:00'));
        $player->changeLifeForm(LifeForm::Trader, $player->getCreatedAt());

        // Confirmed two days after signing up.
        $confirmed = new \DateTimeImmutable('2026-11-19 20:00');
        $player->startPlaying($confirmed);

        self::assertSame($confirmed, $player->getCreatedAt());
        self::assertTrue($economy->hasStarterBonus($player, $confirmed->modify('+23 hours')), 'a full day of bonus after confirming');
        self::assertFalse($economy->hasStarterBonus($player, $confirmed->modify('+25 hours')));
        self::assertEquals($confirmed->modify(LifeForm::CHANGE_COOLDOWN), $player->getNextLifeFormChangeAt());
        self::assertSame(LifeForm::Trader, $player->getLifeForm(), 'the chosen life form stays');
    }
}
