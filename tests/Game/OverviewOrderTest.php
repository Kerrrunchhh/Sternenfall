<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\OverviewSection;
use PHPUnit\Framework\TestCase;

/** Every player orders the overview boxes; boxes he never placed follow in the default order. */
final class OverviewOrderTest extends TestCase
{
    public function testDefaultOrderPutsTheQueuesRightAfterTheFleetMovements(): void
    {
        $player = new User('neu');
        self::assertSame(OverviewSection::cases(), $player->getOverviewOrder());
        self::assertSame($player->overviewPosition('movements') + 1, $player->overviewPosition('queues'));
    }

    public function testThePlayersOrderWinsAndNewBoxesFollow(): void
    {
        $player = new User('ordner');
        $player->setOverviewOrder([OverviewSection::Hangar, OverviewSection::Queues]);

        self::assertSame(1, $player->overviewPosition(OverviewSection::Hangar));
        self::assertSame(2, $player->overviewPosition('queues'));
        self::assertSame(3, $player->overviewPosition('contacts'), 'the rest in the default order');
        self::assertCount(\count(OverviewSection::cases()), $player->getOverviewOrder());
    }
}
