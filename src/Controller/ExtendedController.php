<?php

namespace App\Controller;

use App\Game\FleetFilters;
use App\Payment\PaymentException;
use App\Payment\PayPalSubscriptions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/** Extended account: 5 € a month via a PayPal subscription. */
class ExtendedController extends AbstractGameController
{
    public function __construct(private readonly PayPalSubscriptions $paypal)
    {
    }

    #[Route('/erweitert', name: 'app_extended', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderGame('game/extended.html.twig', $this->currentPlanet(), [
            'paypal' => $this->paypal,
            'filters_normal' => FleetFilters::MAX_FILTERS_NORMAL,
            'filters_extended' => FleetFilters::MAX_FILTERS,
        ]);
    }

    /** Called by the page after PayPal's window approved the subscription. */
    #[Route('/erweitert/aktivieren', name: 'app_extended_activate', methods: ['POST'])]
    #[IsCsrfTokenValid('extended', tokenKey: '_token')]
    public function activate(Request $request): JsonResponse
    {
        try {
            $this->paypal->activate($this->player(), $request->request->getString('subscription'));
            $this->addFlash('success', 'Danke! Dein Account ist jetzt erweitert.');

            return new JsonResponse(['ok' => true]);
        } catch (PaymentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    #[Route('/erweitert/kuendigen', name: 'app_extended_cancel', methods: ['POST'])]
    #[IsCsrfTokenValid('extended', tokenKey: '_token')]
    public function cancel(): Response
    {
        $this->attempt(fn () => $this->paypal->cancel($this->player()), 'Abo gekündigt. Der bezahlte Zeitraum läuft noch bis zum Ende.');

        return $this->redirectToRoute('app_extended');
    }

    /** PayPal's webhook (configure https://…/paypal/webhook in the PayPal app). */
    #[Route('/paypal/webhook', name: 'app_paypal_webhook', methods: ['POST'])]
    public function webhook(Request $request): Response
    {
        $headers = array_map(static fn (array $values): string => (string) ($values[0] ?? ''), $request->headers->all());

        return new Response('', $this->paypal->handleWebhook($headers, $request->getContent()) ? 200 : 400);
    }
}
