<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\OverviewSection;
use PHPUnit\Framework\TestCase;

/** Every player orders the overview boxes; boxes he never placed go where the default order puts them. */
final class OverviewOrderTest extends TestCase
{
    public function testDefaultOrderPutsTheQueuesRightAfterTheFleetMovements(): void
    {
        $player = new User('neu');
        self::assertSame(OverviewSection::cases(), $player->getOverviewOrder());
        self::assertSame($player->overviewPosition('movements') + 1, $player->overviewPosition('queues'));
    }

    public function testThePlayersOrderWinsAndNewBoxesGoAfterTheirDefaultPredecessor(): void
    {
        $player = new User('ordner');
        $player->setOverviewOrder([OverviewSection::Hangar, OverviewSection::Queues]);
        $order = $player->getOverviewOrder();

        self::assertCount(\count(OverviewSection::cases()), $order);
        self::assertLessThan($player->overviewPosition('queues'), $player->overviewPosition(OverviewSection::Hangar), 'his order of the boxes he placed stays');
        self::assertSame($player->overviewPosition('queues') + 1, $player->overviewPosition('transport_requests'), 'transport requests right after the queues, as by default');
    }

    public function testANewBoxAppearsAtItsPlaceInASavedOrder(): void
    {
        // A player who saved his order before the black board box existed, contacts moved down.
        $saved = array_values(array_filter(OverviewSection::cases(), static fn (OverviewSection $s): bool => OverviewSection::Board !== $s && OverviewSection::Contacts !== $s));
        $saved[] = OverviewSection::Contacts;
        $player = new User('alt');
        $player->setOverviewOrder($saved);

        self::assertSame($player->overviewPosition('contacts') + 1, $player->overviewPosition('board'), 'the black board right below the quick contacts');
        self::assertSame(OverviewSection::Movements, $player->getOverviewOrder()[0], 'the rest as he saved it');
    }
}
