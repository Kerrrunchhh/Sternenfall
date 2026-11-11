<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\EventListener\VacationListener;
use App\Game\Economy;
use App\Game\Fusion;
use App\Game\ResearchType;
use PHPUnit\Framework\TestCase;

final class RoundTwoTest extends TestCase
{
    public function testRadarTechSetsTheRecallWindow(): void
    {
        $player = new User('funker');
        self::assertSame(0, Economy::recallWindow($player), 'without Kommunikationstechnik no recall');
        $player->setResearchLevel(ResearchType::RadarTech, 1);
        self::assertSame(5, Economy::recallWindow($player));
        $player->setResearchLevel(ResearchType::RadarTech, 20);
        self::assertSame(300, Economy::recallWindow($player), 'level 20: 5 minutes');
        $player->setResearchLevel(ResearchType::RadarTech, 25);
        self::assertGreaterThan(300, Economy::recallWindow($player), 'no ceiling');
    }

    public function testResearchStaysOnItsSlotWhenFusedIntoABiggerFusion(): void
    {
        $now = new \DateTimeImmutable('2026-01-01');
        $player = new User('forscher', $now);
        $top = new Planet($player, 'Oben', 15, 1, 1, $now);
        $lower = new Planet($player, 'Labor', 15, 1, 2, $now);
        $player->startResearch(ResearchType::FusionTech, 5, $now->modify('+30 days'), $lower);
        self::assertSame('15:1:2', $player->getResearchSlot());

        Fusion::merge($top, [$lower]);
        self::assertTrue($player->isResearchingOn($top), 'still running on slot 2, now part of the fusion');

        $other = new Planet(new User('x'), 'Fremd', 15, 1, 3, $now);
        self::assertFalse($player->isResearchingOn($other));
    }

    public function testOnVacationOnlyTalkingAndLookingAround(): void
    {
        foreach (['app_mail', 'app_mail_send', 'app_ticker_post', 'app_alliance_post', 'app_galaxy', 'app_diplomacy_vote', 'app_alliance_rank'] as $route) {
            self::assertTrue(VacationListener::allowed($route), $route);
        }
        foreach (['app_radar', 'app_fleet', 'app_fleets_bulk', 'app_build', 'app_research_start', 'app_trade', 'app_galaxy_spy', 'app_overview'] as $route) {
            self::assertFalse(VacationListener::allowed($route), $route);
        }
    }
}
