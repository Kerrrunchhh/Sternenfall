<?php

namespace App\Tests\Game;

use App\Controller\BattleReportController;
use App\Entity\User;
use App\Game\Battle;
use App\Game\BattleGroup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/** The battle report can replay a battle: every step, every shot, and what each unit did. */
class BattleReplayTest extends TestCase
{
    public function testTheBattleKeepsEveryShotInFiringOrder(): void
    {
        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, new User('a'), ['falke' => 10]);
        $battle->add(BattleGroup::DEFENDER, new User('d'), ['invasion_ship' => 2, 'lanze' => 5], 'hangar');

        $replay = $battle->fight()->replay();

        $firstRound = array_values(array_filter($replay['steps'], static fn (array $step): bool => 1 === $step['round']));
        self::assertSame([2, 4], array_column($firstRound, 'order'), 'Falke (2), then Lanze (4); the invasion ship does not fire');
        self::assertGreaterThan(1, max(array_column($replay['steps'], 'round')), 'more rounds follow');
        $falke = $replay['steps'][0]['shots'][0];
        self::assertSame(10, $falke['units']);
        self::assertSame([['group' => 1, 'damage' => 2850.0, 'destroyed' => 0]], $falke['hits'], 'all damage on the invasion ships, triple for fighters (attack 95)');
        self::assertGreaterThan(1200.0, $replay['groups'][0]['damage'], 'the Falken fire again in later rounds');
        self::assertSame(10, $replay['groups'][0]['count']);
    }

    public function testTheSummaryAddsUpPerPlayerAndTypeAndMarksTheViewersUnits(): void
    {
        $pilot = new User('Pilotin');
        (new \ReflectionProperty(User::class, 'id'))->setValue($pilot, 7);
        $battle = new Battle();
        $battle->add(BattleGroup::ATTACKER, $pilot, ['falke' => 5], 'attacker');
        $battle->add(BattleGroup::ATTACKER, $pilot, ['falke' => 5], 'joiner:1');
        $battle->add(BattleGroup::DEFENDER, new User('Gegner'), ['freighter' => 2], 'hangar');

        $view = BattleReportController::replayView($battle->fight()->replay(), 7, new IdentityTranslator());

        self::assertCount(2, $view['summary'], 'two fleets with Falken count as one row');
        self::assertSame(['Pilotin', 'Falke', true, 10, 2], [$view['summary'][0]['owner'], $view['summary'][0]['label'], $view['summary'][0]['own'], $view['summary'][0]['count'], $view['summary'][0]['destroyed']]);
        self::assertFalse($view['summary'][1]['own']);
        // Combat experience per player with his side; the reader's own is marked.
        self::assertSame(['attacker', 'Pilotin', true], [$view['experience'][0]['side'], $view['experience'][0]['owner'], $view['experience'][0]['own']]);
        self::assertGreaterThan(0, $view['experience'][0]['experience']);
        self::assertSame(0, $view['summary'][1]['alive']);
    }
}
