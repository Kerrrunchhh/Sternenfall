<?php

namespace App\Tests\Game;

use App\Entity\Alliance;
use App\Entity\TradePost;
use App\Entity\User;
use App\Game\AllianceRank;
use App\Game\LifeForm;
use App\Game\TradeDeal;
use PHPUnit\Framework\TestCase;

class TradeTest extends TestCase
{
    public function testBuysWhatStockCreditsAndCargoAllow(): void
    {
        $deal = TradeDeal::compute(
            order: ['iron' => 5000, 'water' => 1000, 'fusionator' => 3],
            stock: ['iron' => 10_000, 'water' => 300, 'fusionator' => 2],
            prices: ['iron' => 1.0, 'water' => 2.0, 'fusionator' => 100.0],
            credits: 3000,
            freeCargo: 4000,
        );

        // Iron limited by cargo 4000 and credits 3000 → 3000; water: no credits left. Fusionators are no trade post goods.
        self::assertSame(['iron' => 3000.0], $deal->bought);
        self::assertSame(3000.0, $deal->price);
    }

    public function testGoodsWithoutPriceAreNotSold(): void
    {
        $deal = TradeDeal::compute(['titanium' => 100], ['titanium' => 500], [], 1_000_000, 1000);

        self::assertTrue($deal->isEmpty());
        self::assertSame(0.0, $deal->price);
    }

    public function testOwnerPicksUpForFree(): void
    {
        $deal = TradeDeal::compute(['titanium' => 100], ['titanium' => 500], [], 0, 1000, free: true);

        self::assertSame(['titanium' => 100.0], $deal->bought);
        self::assertSame(0.0, $deal->price);
    }

    public function testAccessRules(): void
    {
        $now = new \DateTimeImmutable();
        $trader = new User('trader');
        $trader->changeLifeForm(LifeForm::Trader, $now);
        $post = new TradePost($trader, 'Basar', 1, 1, 7, $now);

        $settler = new User('settler');
        $pirate = new User('pirate');
        $pirate->changeLifeForm(LifeForm::Pirate, $now);
        $ally = new User('ally');
        $alliance = new Alliance('Bund', 'BND', LifeForm::Settler, $now);
        $ally->joinAlliance($alliance, AllianceRank::Member);

        self::assertTrue($post->isAccessibleTo($pirate), 'unrestricted posts are open to everybody');

        $post->setAllowedLifeForms([LifeForm::Settler]);
        self::assertTrue($post->isAccessibleTo($settler));
        self::assertTrue($post->isAccessibleTo($ally), 'ally is a settler too');
        self::assertFalse($post->isAccessibleTo($pirate));
        self::assertTrue($post->isAccessibleTo($trader), 'the owner always');

        $post->setAllowedLifeForms([]);
        $post->setAllowedAlliances([$alliance]);
        self::assertTrue($post->isAccessibleTo($ally));
        self::assertFalse($post->isAccessibleTo($settler), 'only the alliance now');
    }

    public function testAbandonedPostSellsNothingButKeepsStock(): void
    {
        $owner = new User('ex-trader');
        $buyer = new User('buyer');
        $post = new TradePost($owner, 'Basar', 1, 1, 7, new \DateTimeImmutable());
        $post->setPrices(['iron' => 2]);
        $post->addStock('iron', 1000);

        $post->abandon();

        self::assertFalse($post->isActive());
        self::assertSame([], $post->getPrices());
        self::assertSame(1000.0, $post->getStock('iron'), 'the goods stay');
        self::assertFalse($post->isAccessibleTo($buyer), 'nobody can buy any more');
        self::assertTrue($post->isAccessibleTo($owner), 'the owner can still collect');
    }

    public function testPricesAndStock(): void
    {
        $post = new TradePost(new User('t'), 'Basar', 1, 1, 7, new \DateTimeImmutable());
        $post->setPrices(['iron' => 1.234, 'water' => 0, 'fusionator' => 50_000, 'bogus' => 5]);

        self::assertSame(['iron' => 1.23], $post->getPrices(), 'only resources, no fusionators');

        $post->addStock('iron', 500);
        $post->addStock('iron', -500);
        self::assertTrue($post->isEmpty());
    }
}
