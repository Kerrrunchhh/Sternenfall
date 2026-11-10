<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\FleetFilters;
use App\Game\GameException;
use App\Payment\PaymentException;
use App\Payment\PayPalSubscriptions;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Extended account: PayPal subscription and what it unlocks. */
final class ExtendedAccountTest extends TestCase
{
    private const string FILTER = '{"alliance":true,"rules":[{"kind":"contains","text":"a"}]}';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-10 12:00');
    }

    public function testNormalAccountsSaveTwoFiltersExtendedOnesTwenty(): void
    {
        $player = new User('filterer', $this->now);
        $filters = new FleetFilters($this->createStub(EntityManagerInterface::class), new MockClock($this->now));
        $filters->save($player, 'Eins', '#ff0000', self::FILTER);
        $filters->save($player, 'Zwei', '#00ff00', self::FILTER);
        $filters->save($player, 'Zwei', '#0000ff', self::FILTER); // replacing is fine

        try {
            $filters->save($player, 'Drei', '#ffffff', self::FILTER);
            self::fail('a third filter needs an extended account');
        } catch (GameException $e) {
            self::assertStringContainsString('erweiterten Account', $e->getMessage());
        }

        $player->extendUntil($this->now->modify('+1 month'));
        $filters->save($player, 'Drei', '#ffffff', self::FILTER);
        self::assertCount(3, $player->getFleetFilters());
        self::assertFalse($player->isExtended($this->now->modify('+2 months')), 'runs out');
    }

    public function testActivationChecksPlanAndOwnerAndExtendsToTheNextBillingDate(): void
    {
        $player = $this->player(7);
        $paypal = $this->paypal([
            $this->token(),
            new MockResponse(json_encode(['id' => 'I-ABC123456', 'status' => 'ACTIVE', 'plan_id' => 'P-PLAN', 'custom_id' => '7', 'billing_info' => ['next_billing_time' => '2026-12-10T12:00:00Z']])),
        ]);

        $paypal->activate($player, 'I-ABC123456');

        self::assertSame('I-ABC123456', $player->getSubscriptionId());
        self::assertSame('ACTIVE', $player->getSubscriptionStatus());
        self::assertEquals(new \DateTimeImmutable('2026-12-11T12:00:00Z'), $player->getExtendedUntil(), 'next billing date plus a day of grace');
    }

    public function testSomeoneElsesSubscriptionIsRejected(): void
    {
        $player = $this->player(7);
        $paypal = $this->paypal([
            $this->token(),
            new MockResponse(json_encode(['id' => 'I-ABC123456', 'status' => 'ACTIVE', 'plan_id' => 'P-PLAN', 'custom_id' => '8'])),
        ]);

        $this->expectException(PaymentException::class);
        $paypal->activate($player, 'I-ABC123456');
    }

    public function testWebhookNeedsAValidSignatureAndRenewsFromPayPalsCurrentState(): void
    {
        $player = $this->player(7);
        $player->setSubscription('I-ABC123456', 'ACTIVE');
        $player->extendUntil($this->now->modify('+1 day'));
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturn($player);
        $event = json_encode(['event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => ['billing_agreement_id' => 'I-ABC123456']]);

        $forged = $this->paypal([$this->token(), new MockResponse(json_encode(['verification_status' => 'FAILURE']))], $users);
        self::assertFalse($forged->handleWebhook([], $event));

        $genuine = $this->paypal([
            $this->token(),
            new MockResponse(json_encode(['verification_status' => 'SUCCESS'])),
            new MockResponse(json_encode(['id' => 'I-ABC123456', 'status' => 'ACTIVE', 'plan_id' => 'P-PLAN', 'custom_id' => '7', 'billing_info' => ['next_billing_time' => '2027-01-10T12:00:00Z']])),
        ], $users);
        self::assertTrue($genuine->handleWebhook([], $event));
        self::assertEquals(new \DateTimeImmutable('2027-01-11T12:00:00Z'), $player->getExtendedUntil());
    }

    public function testNotOfferedWithoutCredentials(): void
    {
        $paypal = new PayPalSubscriptions(new MockHttpClient(), $this->createStub(EntityManagerInterface::class), $this->createStub(UserRepository::class), new MockClock($this->now), new NullLogger(), 'https://api-m.sandbox.paypal.com', '', '', '', '');
        self::assertFalse($paypal->isConfigured());
        self::assertTrue($paypal->isSandbox());
    }

    private function player(int $id): User
    {
        $player = new User('zahler', $this->now);
        (new \ReflectionProperty(User::class, 'id'))->setValue($player, $id);

        return $player;
    }

    private function token(): MockResponse
    {
        return new MockResponse(json_encode(['access_token' => 'tok']));
    }

    /** @param list<MockResponse> $responses */
    private function paypal(array $responses, ?UserRepository $users = null): PayPalSubscriptions
    {
        return new PayPalSubscriptions(new MockHttpClient($responses), $this->createStub(EntityManagerInterface::class), $users ?? $this->createStub(UserRepository::class), new MockClock($this->now), new NullLogger(), 'https://api-m.sandbox.paypal.com', 'client', 'secret', 'P-PLAN', 'WH-1');
    }
}
