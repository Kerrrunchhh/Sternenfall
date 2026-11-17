<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\TradePost;
use App\Entity\User;
use App\Game\FleetMission;
use App\Game\FleetService;
use App\Game\GameException;
use PHPUnit\Framework\TestCase;

/** Since 2026-11-17 nobody stations at a free slot: nothing supplies a fleet there, it would be stuck. */
class FreeSlotStationTest extends TestCase
{
    public function testStationingIsOnlyAllowedAtPlanetsAndTradePosts(): void
    {
        $now = new \DateTimeImmutable('2026-11-17 12:00');
        $player = new User('pilot', $now);
        $validate = new \ReflectionMethod(FleetService::class, 'validateMission');
        $service = (new \ReflectionClass(FleetService::class))->newInstanceWithoutConstructor();
        $ships = ['freighter' => 2];

        try {
            $validate->invoke($service, FleetMission::Station, $player, null, $ships, true, null, []);
            self::fail('a free slot is refused, even for a named fleet');
        } catch (GameException $e) {
            self::assertStringContainsString('freien Plätzen', $e->getMessage());
        }

        $own = new Planet($player, 'Heim', 1, 2, 3, $now);
        $validate->invoke($service, FleetMission::Station, $player, $own, $ships, false, null, []);
        $foreign = new Planet(new User('freund', $now), 'Bei Freunden', 1, 2, 4, $now);
        $validate->invoke($service, FleetMission::Station, $player, $foreign, $ships, true, null, []);
        $post = new TradePost(new User('Handelsgilde', $now), 'Zentralbörse', 1, 250, 1, $now);
        $validate->invoke($service, FleetMission::Station, $player, null, $ships, true, $post, []);
        $this->addToAssertionCount(3); // own planet, other planet (named), trade post
    }
}
