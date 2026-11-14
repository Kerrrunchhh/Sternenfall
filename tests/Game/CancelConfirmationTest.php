<?php

namespace App\Tests\Game;

use App\Entity\BuildingOrder;
use App\Entity\Planet;
use App\Entity\ShipyardOrder;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\QueueTimeline;
use App\Game\ResearchType;
use App\Game\Resources;
use App\Game\ShipType;
use PHPUnit\Framework\TestCase;

/** Cancelling asks first only from 10 % progress on – the numbers behind it. */
class CancelConfirmationTest extends TestCase
{
    private \DateTimeImmutable $now;
    private Planet $planet;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-14 12:00:00');
        $this->planet = new Planet(new User('Pilotin', $this->now), 'Heimat', 1, 1, 1, $this->now);
    }

    public function testBuildingProgress(): void
    {
        $order = new BuildingOrder($this->planet, BuildingType::BioCell, 2, new Resources(), 0);
        self::assertSame(0.0, $order->progress($this->now), 'waiting in the queue');

        $order->start($this->now->modify('-9 minutes'), 6000); // 540 of 6000 seconds
        self::assertLessThan(QueueTimeline::CONFIRM_CANCEL_FROM, $order->progress($this->now));

        $order->start($this->now->modify('-11 minutes'), 6000);
        self::assertEqualsWithDelta(0.11, $order->progress($this->now), 0.001);

        $order->stop($this->now);
        self::assertEqualsWithDelta(0.11, $order->progress($this->now->modify('+5 days')), 0.001, 'a paused order keeps its progress');
    }

    public function testShipyardProgressCountsBuiltUnitsAndTheOneInTheWorks(): void
    {
        // 10 ships à 100 s: the first starts 50 s ago – 5 % of the order.
        $order = new ShipyardOrder($this->planet, ShipType::LargeFreighter, 10, 100, $this->now->modify('-50 seconds'));
        self::assertEqualsWithDelta(0.05, $order->progress($this->now), 0.001);

        $later = $this->now->modify('+100 seconds'); // one done, the second half way
        $order->advance($later);
        self::assertSame(9, $order->getRemaining());
        self::assertEqualsWithDelta(0.15, $order->progress($later), 0.001);
    }

    public function testResearchProgress(): void
    {
        $player = new User('Forscherin', $this->now);
        self::assertSame(0.0, $player->getResearchProgress($this->now));

        $player->startResearch(ResearchType::Espionage, 1, $this->now->modify('+9000 seconds'), $this->planet, 10000);
        self::assertEqualsWithDelta(0.1, $player->getResearchProgress($this->now), 0.001);
        self::assertSame(0.0, $player->getResearchProgress($this->now->modify('-1000 seconds')));

        $player->startResearch(ResearchType::Espionage, 1, $this->now->modify('+9000 seconds'), $this->planet);
        self::assertSame(1.0, $player->getResearchProgress($this->now), 'research from before the duration was kept: ask to be safe');

        $player->clearResearch();
        self::assertSame(0.0, $player->getResearchProgress($this->now));
    }
}
