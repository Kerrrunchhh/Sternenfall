<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The sample battle report linked from the news: public, always there, with replay and summary. */
class BattleExampleTest extends WebTestCase
{
    public function testAnybodyCanWatchTheSampleBattle(): void
    {
        $client = static::createClient([], ['HTTP_ACCEPT_LANGUAGE' => 'de']);

        $crawler = $client->request('GET', '/kampfbericht/beispiel');

        self::assertResponseIsSuccessful();
        $replay = json_decode((string) $crawler->filter('[data-battle-replay]')->attr('data-battle-replay'), true);
        self::assertNotEmpty($replay['steps']);
        self::assertSame('Kommandantin Vega', $replay['groups'][0]['owner']);
        self::assertTrue($replay['groups'][0]['own'], 'the attackers show as the reader\'s own');
        self::assertGreaterThan(0, $crawler->filter('.summary-table tr.own')->count());
        self::assertSelectorTextContains('.battle-experience', 'Kommandantin Vega');
    }

    public function testTheSameAttackFleetDoesBetterAgainstAnAttackFleetThanAgainstADefense(): void
    {
        $client = static::createClient([], ['HTTP_ACCEPT_LANGUAGE' => 'de']);
        $survivors = [];
        foreach (['angriff', 'verteidigung'] as $example) {
            $crawler = $client->request('GET', '/kampfbericht/beispiel/'.$example);
            self::assertResponseIsSuccessful();
            $groups = json_decode((string) $crawler->filter('[data-battle-replay]')->attr('data-battle-replay'), true)['groups'];
            $survivors[$example] = array_sum(array_column(array_filter($groups, static fn (array $g): bool => 'attacker' === $g['side']), 'alive'));
        }

        self::assertGreaterThan($survivors['verteidigung'], $survivors['angriff']);
    }

    public function testTheJointAttackExampleHasSeveralPlayersOnEachSide(): void
    {
        $client = static::createClient([], ['HTTP_ACCEPT_LANGUAGE' => 'de']);

        $crawler = $client->request('GET', '/kampfbericht/beispiel/verband');

        self::assertResponseIsSuccessful();
        $owners = array_unique(array_column(json_decode((string) $crawler->filter('[data-battle-replay]')->attr('data-battle-replay'), true)['groups'], 'owner'));
        self::assertCount(4, $owners);
        self::assertCount(2, $crawler->filter('.battle-experience'), 'experience for both sides');
    }
}
