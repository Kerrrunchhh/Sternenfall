<?php

namespace App\Payment;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\Translation\t;

/**
 * Extended accounts as a PayPal subscription (plan of 5 € per month, set up in the PayPal dashboard).
 * The browser approves the subscription with PayPal's button; we confirm it here with PayPal's API
 * and keep it running through webhooks. Each paid month extends the account to the next billing date
 * plus a day of grace; a cancelled subscription simply runs out.
 */
final class PayPalSubscriptions
{
    private const string GRACE = '+1 day';

    private ?string $token = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'PAYPAL_API')] private readonly string $api,
        #[Autowire(env: 'PAYPAL_CLIENT_ID')] private readonly string $clientId,
        #[Autowire(env: 'PAYPAL_CLIENT_SECRET')] private readonly string $clientSecret,
        #[Autowire(env: 'PAYPAL_PLAN_ID')] private readonly string $planId,
        #[Autowire(env: 'PAYPAL_WEBHOOK_ID')] private readonly string $webhookId,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret && '' !== $this->planId;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function planId(): string
    {
        return $this->planId;
    }

    public function isSandbox(): bool
    {
        return str_contains($this->api, 'sandbox');
    }

    /** After the player approved the subscription in the PayPal window. */
    public function activate(User $user, string $subscriptionId): void
    {
        if (!$this->isConfigured() || 1 !== preg_match('/^I-[A-Z0-9]{6,40}$/', $subscriptionId)) {
            throw new PaymentException(t('Das Abo konnte nicht bestätigt werden.'));
        }
        $subscription = $this->fetch($subscriptionId);
        if (($subscription['plan_id'] ?? null) !== $this->planId || (string) ($subscription['custom_id'] ?? '') !== (string) $user->getId()) {
            throw new PaymentException(t('Dieses Abo gehört nicht zu deinem Account.'));
        }
        if (!\in_array($subscription['status'] ?? '', ['ACTIVE', 'APPROVED'], true)) {
            throw new PaymentException(t('PayPal meldet das Abo noch nicht als aktiv. Versuche es gleich noch einmal.'));
        }
        $user->setSubscription($subscriptionId, $subscription['status']);
        $this->apply($user, $subscription);
        $this->em->flush();
    }

    /** Ends the subscription at PayPal; the paid time still runs out. */
    public function cancel(User $user): void
    {
        $id = $user->getSubscriptionId();
        if (null === $id || 'CANCELLED' === $user->getSubscriptionStatus()) {
            throw new PaymentException(t('Du hast kein laufendes Abo.'));
        }
        $response = $this->httpClient->request('POST', $this->api.'/v1/billing/subscriptions/'.rawurlencode($id).'/cancel', [
            'auth_bearer' => $this->token(),
            'json' => ['reason' => 'Vom Spieler im Spiel gekündigt'],
        ]);
        if (!\in_array($response->getStatusCode(), [204, 422], true)) { // 422: already cancelled
            throw new PaymentException(t('PayPal hat die Kündigung nicht angenommen. Du kannst das Abo auch direkt in deinem PayPal-Konto beenden.'));
        }
        $user->setSubscription($id, 'CANCELLED');
        $this->em->flush();
    }

    /**
     * A webhook call from PayPal. Returns false if its signature isn't valid.
     *
     * @param array<string, string> $headers lower-case header names
     */
    public function handleWebhook(array $headers, string $body): bool
    {
        $event = json_decode($body, true);
        if (!$this->isConfigured() || '' === $this->webhookId || !\is_array($event) || !$this->verify($headers, $event)) {
            return false;
        }
        $type = (string) ($event['event_type'] ?? '');
        $resource = \is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $subscriptionId = str_starts_with($type, 'BILLING.SUBSCRIPTION.') ? ($resource['id'] ?? null) : ($resource['billing_agreement_id'] ?? null);
        if (!\is_string($subscriptionId)) {
            return true;
        }
        $user = $this->users->findOneBy(['subscriptionId' => $subscriptionId]);
        if (null === $user && isset($resource['custom_id'])) {
            $user = $this->users->find((int) $resource['custom_id']);
        }
        if (null === $user) {
            $this->logger->warning('PayPal webhook for unknown subscription {id}', ['id' => $subscriptionId]);

            return true;
        }
        // Never trust the event's copy: ask PayPal for the current state.
        $subscription = $this->fetch($subscriptionId);
        if ((string) ($subscription['custom_id'] ?? '') !== (string) $user->getId()) {
            return true;
        }
        $user->setSubscription($subscriptionId, (string) ($subscription['status'] ?? 'UNKNOWN'));
        $this->apply($user, $subscription);
        $this->em->flush();

        return true;
    }

    /** @param array<string, mixed> $subscription */
    private function apply(User $user, array $subscription): void
    {
        if (!\in_array($subscription['status'] ?? '', ['ACTIVE', 'APPROVED'], true)) {
            return;
        }
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $next = $subscription['billing_info']['next_billing_time'] ?? null;
        $until = \is_string($next) ? new \DateTimeImmutable($next) : $now->modify('+1 month');
        $user->extendUntil(max($until, $now)->modify(self::GRACE));
    }

    /** @return array<string, mixed> */
    private function fetch(string $subscriptionId): array
    {
        $response = $this->httpClient->request('GET', $this->api.'/v1/billing/subscriptions/'.rawurlencode($subscriptionId), ['auth_bearer' => $this->token()]);
        if (200 !== $response->getStatusCode()) {
            throw new PaymentException(t('PayPal kennt dieses Abo nicht.'));
        }

        return $response->toArray();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $event
     */
    private function verify(array $headers, array $event): bool
    {
        $response = $this->httpClient->request('POST', $this->api.'/v1/notifications/verify-webhook-signature', [
            'auth_bearer' => $this->token(),
            'json' => [
                'auth_algo' => $headers['paypal-auth-algo'] ?? '',
                'cert_url' => $headers['paypal-cert-url'] ?? '',
                'transmission_id' => $headers['paypal-transmission-id'] ?? '',
                'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
                'transmission_time' => $headers['paypal-transmission-time'] ?? '',
                'webhook_id' => $this->webhookId,
                'webhook_event' => $event,
            ],
        ]);

        return 200 === $response->getStatusCode() && 'SUCCESS' === ($response->toArray(false)['verification_status'] ?? null);
    }

    private function token(): string
    {
        if (null === $this->token) {
            $response = $this->httpClient->request('POST', $this->api.'/v1/oauth2/token', [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'body' => ['grant_type' => 'client_credentials'],
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new PaymentException(t('Die Verbindung zu PayPal ist gerade gestört.'));
            }
            $this->token = (string) $response->toArray()['access_token'];
        }

        return $this->token;
    }
}
