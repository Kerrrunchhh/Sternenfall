<?php

namespace App\Tests\Game;

use App\Entity\BattleReport;
use App\Entity\User;
use App\Game\Battle;
use App\Game\BattleGroup;
use App\Game\BattleSummary;
use App\Game\BattleSummaryForm;
use PHPUnit\Framework\TestCase;

/** The battle summary adds up many reports: losses, plunder, players and their combat experience. */
class BattleSummaryTest extends TestCase
{
    public function testTwoBattlesOfAWarAddUp(): void
    {
        $vega = $this->player('Vega', 1);
        $orsk = $this->player('Orsk', 2);
        $halden = $this->player('Halden', 3);

        // Vega and Orsk attack Halden's planet …
        $first = new Battle();
        $first->add(BattleGroup::ATTACKER, $vega, ['lanze' => 100], 'attacker');
        $first->add(BattleGroup::ATTACKER, $orsk, ['falke' => 50], 'joiner:1');
        $first->add(BattleGroup::DEFENDER, $halden, ['pfeil' => 30, 'laser_turret' => 10], 'hangar');
        $one = $first->fight()->replay();
        $one['loot'] = [['owner' => 'Vega', 'ownerId' => 1, 'resources' => ['iron' => 1000, 'titanium' => 200]], ['owner' => 'Orsk', 'ownerId' => 2, 'resources' => ['iron' => 500]]];

        // … and Halden hits back at Vega.
        $second = new Battle();
        $second->add(BattleGroup::ATTACKER, $halden, ['korsar' => 40], 'attacker');
        $second->add(BattleGroup::DEFENDER, $vega, ['pfeil' => 20], 'hangar');
        $two = $second->fight()->replay();
        $two['loot'] = [];

        // A report from before loot was stored, and an annulled one.
        $old = $first->fight()->replay();
        $annulled = $this->report('d', $two, '2026-11-15 12:00');
        $annulled->annul('Bug', new \DateTimeImmutable());

        $summary = new BattleSummary([$this->report('b', $two, '2026-11-15 11:00'), $this->report('a', $one, '2026-11-15 10:00'), $annulled, $this->report('c', $old, '2026-11-15 09:00')]);

        self::assertSame(['c', 'a', 'b'], array_map(static fn (BattleReport $r): string => $r->getHash()[0], $summary->counted), 'oldest first');
        self::assertCount(1, $summary->annulled);
        self::assertSame(1, $summary->withoutLoot);

        $attacker = $summary->sides[BattleGroup::ATTACKER];
        $defender = $summary->sides[BattleGroup::DEFENDER];
        self::assertSame(1500.0, $attacker['loot']->iron);
        self::assertSame(200.0, $attacker['loot']->titanium);
        self::assertArrayHasKey('laser_turret', $defender['turrets'], 'turrets lost apart from ships');
        self::assertArrayNotHasKey('laser_turret', $defender['ships']);
        self::assertSame(
            round(array_sum(array_column($one['experience'], 'experience')) * 2 + array_sum(array_column($two['experience'], 'experience')), 1),
            round($attacker['experience'] + $defender['experience'], 1),
        );

        $vegaRow = $summary->players['id:1'];
        self::assertSame(['attacker' => true, 'defender' => true], $vegaRow['sides'], 'Vega attacked and defended');
        self::assertSame(3, $vegaRow['battles']);
        self::assertSame(1000.0, $vegaRow['loot']->iron);
        self::assertSame(3, $summary->players['id:3']['battles']);
    }

    public function testLinksAreFoundInAnyText(): void
    {
        $form = new BattleSummaryForm("Kampfbericht teilen: https://starfallnewhorizons.com/kampfbericht/0123456789abcdef01234567\n/kampfbericht/0123456789ABCDEF01234567 und 89abcdef0123456789abcdef");

        self::assertSame(['0123456789abcdef01234567', '89abcdef0123456789abcdef'], $form->hashes());
    }

    private function player(string $name, int $id): User
    {
        $user = new User($name);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    /** @param array<string, mixed> $replay */
    private function report(string $hash, array $replay, string $at): BattleReport
    {
        $report = new BattleReport(str_pad($hash, 24, '0'), 'Kampfbericht', '', new \DateTimeImmutable($at));
        $report->setReplay($replay);

        return $report;
    }
}
