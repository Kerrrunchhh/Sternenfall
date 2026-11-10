<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\Economy;
use App\Game\ResearchType;
use PHPUnit\Framework\TestCase;

class LongResearchTest extends TestCase
{
    private Economy $economy;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->economy = new Economy(1.0);
        $this->now = new \DateTimeImmutable('2026-01-01');
    }

    public function testFusionAndColonizationAreLongTerm(): void
    {
        foreach (ResearchType::cases() as $type) {
            $long = \in_array($type, [ResearchType::FusionTech, ResearchType::Colonization], true);
            self::assertSame($long, $type->isLongTerm(), $type->label());
        }
        self::assertSame(49, ResearchType::FusionTech->maxLevel(), 'no system has more than 50 planets');
        foreach (ResearchType::cases() as $type) {
            if (ResearchType::FusionTech !== $type) {
                self::assertNull($type->maxLevel(), $type->label().' keeps improving');
            }
        }
        self::assertGreaterThan($this->economy->researchSeconds(ResearchType::Colonization, 10, $this->lab(980)), $this->economy->researchSeconds(ResearchType::Colonization, 11, $this->lab(980)), 'colonization goes on beyond level 10');
    }

    public function testColonizationStartsQuicklyThenEachLevelTakesVeryLong(): void
    {
        // The model player's labs at each colonization level (fused planets × lab level).
        $days = fn (int $level, int $labs): float => $this->economy->researchSeconds(ResearchType::Colonization, $level, $this->lab($labs)) / 86400;

        self::assertEqualsWithDelta(1, $days(1, 4), 0.1, 'first 10 slots within a day');
        self::assertEqualsWithDelta(14, $days(2, 75), 0.5, 'next 10 within two weeks');
        self::assertEqualsWithDelta(341, $days(3, 214), 5, 'then as long as 10 single steps');
        self::assertGreaterThan(500, $days(5, 685));
        self::assertGreaterThan($days(2, 75), $days(2, 10), 'smaller labs take longer');

        $costs = array_sum(array_map(static fn (int $n): float => $n ** 1.5, range(1, 10)));
        self::assertEqualsWithDelta(2000 * $costs, $this->economy->researchCost(ResearchType::Colonization, 1)->iron, 1);
    }

    public function testDurationDependsOnlyOnTheResearchLabs(): void
    {
        $small = $this->lab(1);
        $fused = $this->lab(980); // 49 planets with labs at level 20

        $slow = $this->economy->researchSeconds(ResearchType::FusionTech, 50, $small);
        $fast = $this->economy->researchSeconds(ResearchType::FusionTech, 50, $fused);

        self::assertEqualsWithDelta(60, $fast / 86400, 0.5, 'top level: about two months with fused labs');
        self::assertEqualsWithDelta(981 / 2, $slow / $fast, 1, 'time is inversely proportional to 1 + lab level');
    }

    public function testFastAtFirstThenVerySlow(): void
    {
        // A well developed player: fuses as many planets as allowed, labs grow from 4 to 20.
        $total = 0;
        $durations = [];
        foreach (range(1, 49) as $level) {
            $labs = max(1, $level - 1) * (4 + 16 * $level / 50);
            $durations[$level] = $this->economy->researchSeconds(ResearchType::FusionTech, $level, $this->lab((int) round($labs)));
            $total += $durations[$level];
        }

        self::assertLessThan(86400, $durations[1], 'level 1 within a day');
        self::assertGreaterThan(30 * 86400, $durations[30], 'level 30 takes over a month');
        self::assertLessThan(62 * 86400, max($durations), 'no level much longer than two months');
        // Both long-term researches share the research slot: together about ten years.
        self::assertEqualsWithDelta(9.0, 2 * $total / (365 * 86400), 0.7);
    }

    public function testCostsGrowGentlyInsteadOfDoubling(): void
    {
        $cost = $this->economy->researchCost(ResearchType::FusionTech, 50);

        self::assertEqualsWithDelta(200_000 * 50 ** 1.5, $cost->iron, 1);
        self::assertSame(400.0, $this->economy->researchCost(ResearchType::SolidDrive, 1)->iron);
        self::assertSame(800.0, $this->economy->researchCost(ResearchType::SolidDrive, 2)->iron, 'normal research still doubles');
    }

    public function testColonySlotsCountWeldedPlanetsOneByOne(): void
    {
        $player = new User('siedler', $this->now);
        $top = new Planet($player, 'A', 1, 1, 1, $this->now);
        \App\Game\Fusion::merge($top, [new Planet($player, 'B', 1, 1, 2, $this->now), new Planet($player, 'C', 1, 1, 3, $this->now)]);
        $player->getPlanets()->removeElement($player->getPlanets()->get(1));
        $player->getPlanets()->removeElement($player->getPlanets()->get(2));
        new Planet($player, 'D', 2, 1, 1, $this->now);

        self::assertSame(4, Economy::ownedSlots($player), 'fusion of three plus one planet');
        self::assertSame(1, $this->economy->colonySlots($player), 'without research only the home planet');
        $player->setResearchLevel(ResearchType::Colonization, 1);
        self::assertSame(10, $this->economy->colonySlots($player));
        $player->setResearchLevel(ResearchType::Colonization, 2);
        self::assertSame(20, $this->economy->colonySlots($player));
    }

    private function lab(int $level): Planet
    {
        $planet = new Planet(new User('forscher', $this->now), 'Labor', 1, 1, 1, $this->now);
        $planet->setBuildingLevel(BuildingType::ResearchLab, $level);

        return $planet;
    }
}
