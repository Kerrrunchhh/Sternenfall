<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Creates the PayPal product, the monthly subscription plan and the webhook for the extended
 * account with the credentials from PAYPAL_CLIENT_ID / PAYPAL_CLIENT_SECRET (sandbox or live,
 * see PAYPAL_API) and prints PAYPAL_PLAN_ID and PAYPAL_WEBHOOK_ID for .env.local.
 */
#[AsCommand(name: 'app:paypal:setup', description: 'Creates the PayPal subscription plan and webhook for the extended account')]
final readonly class PayPalSetupCommand
{
    private const array EVENTS = [
        'BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.UPDATED', 'BILLING.SUBSCRIPTION.RE-ACTIVATED',
        'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.SUSPENDED', 'BILLING.SUBSCRIPTION.EXPIRED',
        'PAYMENT.SALE.COMPLETED',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'PAYPAL_API')] private string $api,
        #[Autowire(env: 'PAYPAL_CLIENT_ID')] private string $clientId,
        #[Autowire(env: 'PAYPAL_CLIENT_SECRET')] private string $clientSecret,
        #[Autowire(env: 'DEFAULT_URI')] private string $baseUrl,
        #[Autowire(env: 'GAME_NAME')] private string $gameName,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Monthly price in EUR')] string $price = '5.00',
    ): int {
        if ('' === $this->clientId || '' === $this->clientSecret) {
            $io->error('PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET are missing in .env.local.');

            return 1;
        }
        $io->note(str_contains($this->api, 'sandbox') ? 'Sandbox: '.$this->api : 'LIVE: '.$this->api);

        $token = $this->request('POST', '/v1/oauth2/token', ['auth_basic' => [$this->clientId, $this->clientSecret], 'body' => ['grant_type' => 'client_credentials']])['access_token'];

        $product = $this->request('POST', '/v1/catalogs/products', ['auth_bearer' => $token, 'json' => [
            'name' => $this->gameName.' – erweiterter Account',
            'description' => 'Monatliches Abo für den erweiterten Account im Browserspiel '.$this->gameName,
            'type' => 'SERVICE',
        ]]);
        $io->writeln('Product: '.$product['id']);

        $plan = $this->request('POST', '/v1/billing/plans', ['auth_bearer' => $token, 'json' => [
            'product_id' => $product['id'],
            'name' => 'Erweiterter Account, monatlich',
            'billing_cycles' => [[
                'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0,
                'pricing_scheme' => ['fixed_price' => ['value' => number_format((float) $price, 2, '.', ''), 'currency_code' => 'EUR']],
            ]],
            'payment_preferences' => ['auto_bill_outstanding' => true, 'payment_failure_threshold' => 1],
        ]]);

        $url = rtrim($this->baseUrl, '/').'/paypal/webhook';
        $webhookId = null;
        foreach ($this->request('GET', '/v1/notifications/webhooks', ['auth_bearer' => $token])['webhooks'] ?? [] as $existing) {
            if (($existing['url'] ?? null) === $url) {
                $webhookId = $existing['id'];
            }
        }
        $webhookId ??= $this->request('POST', '/v1/notifications/webhooks', ['auth_bearer' => $token, 'json' => [
            'url' => $url,
            'event_types' => array_map(static fn (string $name): array => ['name' => $name], self::EVENTS),
        ]])['id'];

        $io->success('Done. Put these lines into .env.local, then run "composer dump-env prod" and restart PHP-FPM:');
        $io->writeln('PAYPAL_PLAN_ID='.$plan['id']);
        $io->writeln('PAYPAL_WEBHOOK_ID='.$webhookId);
        $io->writeln('(webhook URL: '.$url.')');

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        $response = $this->httpClient->request($method, $this->api.$path, $options);
        $data = $response->toArray(false);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(\sprintf('PayPal %s %s failed (%d): %s', $method, $path, $response->getStatusCode(), json_encode($data)));
        }

        return $data;
    }
}
