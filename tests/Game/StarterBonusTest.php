<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\Economy;
use App\Game\PlanetUpdater;
use App\Game\ResearchType;
use App\Game\ShipType;
use PHPUnit\Framework\TestCase;

/** During their first 24 hours new players build, research and produce twice as fast. */
final class StarterBonusTest extends TestCase
{
    private \DateTimeImmutable $start;
    private Economy $economy;
    private Economy $withoutBonus;

    protected function setUp(): void
    {
        $this->start = new \DateTimeImmutable('2026-11-11 12:00:00');
        $this->economy = new Economy(1.0, starterBonus: true);
        $this->withoutBonus = new Economy(1.0);
    }

    public function testBuildResearchAndShipTimesAreHalvedOnTheFirstDay(): void
    {
        $planet = $this->planet();
        $firstHour = $this->start->modify('+1 hour');
        $nextDay = $this->start->modify('+25 hours');

        $normal = $this->economy->buildSeconds(BuildingType::IronMine, 5, $planet, $nextDay);
        self::assertSame($normal, $this->withoutBonus->buildSeconds(BuildingType::IronMine, 5, $planet, $firstHour), 'no bonus when switched off');
        self::assertEqualsWithDelta($normal / 2, $this->economy->buildSeconds(BuildingType::IronMine, 5, $planet, $firstHour), 1);
        self::assertEqualsWithDelta($this->economy->researchSeconds(ResearchType::Espionage, 3, $planet, $nextDay) / 2, $this->economy->researchSeconds(ResearchType::Espionage, 3, $planet, $firstHour), 1);
        self::assertEqualsWithDelta($this->economy->shipOrderSeconds(ShipType::Pfeil, $planet, 100, $nextDay) / 2, $this->economy->shipOrderSeconds(ShipType::Pfeil, $planet, 100, $firstHour), 1);
    }

    public function testBonusEndsAfterTwentyFourHours(): void
    {
        $player = $this->planet()->getOwner();

        self::assertEquals($this->start->modify('+24 hours'), $this->economy->starterBonusUntil($player));
        self::assertTrue($this->economy->hasStarterBonus($player, $this->start->modify('+23 hours 59 minutes')));
        self::assertFalse($this->economy->hasStarterBonus($player, $this->start->modify('+24 hours')));
        self::assertFalse($this->economy->hasStarterBonus($player, null));
        self::assertNull($this->withoutBonus->starterBonusUntil($player));
        // Half of this stretch lies in the bonus: on average 1.5×.
        self::assertEqualsWithDelta(1.5, $this->economy->starterFactorBetween($player, $this->start->modify('+23 hours'), $this->start->modify('+25 hours')), 1e-9);
    }

    public function testProductionAndCreditsAreDoubledOnlyDuringTheFirstDay(): void
    {
        // 48 hours with the bonus produce as much as 72 hours without: 24 × 2 + 24.
        $withBonus = $this->planet();
        (new PlanetUpdater($this->economy))->update($withBonus, $this->start->modify('+48 hours'));
        $without = $this->planet();
        (new PlanetUpdater($this->withoutBonus))->update($without, $this->start->modify('+72 hours'));

        self::assertEqualsWithDelta($without->getResources()->iron, $withBonus->getResources()->iron, 1.0);
        self::assertEqualsWithDelta($without->getResources()->titanium, $withBonus->getResources()->titanium, 1.0);
        $plain = $this->planet();
        (new PlanetUpdater($this->withoutBonus))->update($plain, $this->start->modify('+48 hours'));
        self::assertGreaterThan($plain->getResources()->iron * 1.4, $withBonus->getResources()->iron, 'not capped: the bonus really adds');
        self::assertGreaterThan($plain->getOwner()->getCredits() * 1.2, $withBonus->getOwner()->getCredits());
    }

    private function planet(): Planet
    {
        $player = new User('neu', $this->start);
        $planet = new Planet($player, 'Heimatwelt', 1, 1, 1, $this->start);
        $planet->setBuildingLevel(BuildingType::IronMine, 5);
        $planet->setBuildingLevel(BuildingType::BioCell, 1);
        $planet->setPopulation(100);

        return $planet;
    }
}
