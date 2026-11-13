<?php

namespace App\Controller;

use App\I18n\When;
use App\Game\FleetFilters;
use App\Game\GameMailer;
use App\Game\Inbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Payment\PaymentException;
use App\Payment\PayPalSubscriptions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

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
    public function activate(Request $request, Inbox $inbox, GameMailer $mailer, TranslatorInterface $translator, EntityManagerInterface $em, #[Autowire(env: 'GAME_NAME')] string $gameName): JsonResponse
    {
        try {
            if ('1' !== $request->request->getString('consent')) {
                throw new PaymentException(t('Bitte bestätige zuerst die AGB und den sofortigen Beginn.'));
            }
            $player = $this->player();
            $this->paypal->activate($player, $request->request->getString('subscription'));
            // Confirmation of the contract and of the consent to the immediate start (§ 312f BGB).
            $subject = t('Bestätigung: erweiterter Account');
            $text = t("Danke für dein Abo! Hiermit bestätigen wir den Vertrag über den erweiterten Account im Spiel {game}:\n\n– Preis: 5,00 € pro Monat, Zahlung über PayPal im Voraus, verlängert sich monatlich, jederzeit zum Monatsende kündbar (im Spiel unter „Erweiterter Account“ oder bei PayPal).\n– Abo-Kennung: {subscription}\n– Abgeschlossen am: {date}\n\nDu hast ausdrücklich verlangt, dass der erweiterte Account sofort beginnt, und bestätigt, dass dir bekannt ist, dass du dadurch dein Widerrufsrecht verlierst.\n\nDie Nutzungsbedingungen mit der Widerrufsbelehrung findest du unter {terms}.", [
                '{game}' => $gameName,
                '{subscription}' => (string) $player->getSubscriptionId(),
                '{date}' => new When(new \DateTimeImmutable()),
                '{terms}' => $this->generateUrl('app_terms', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
            $inbox->send($player, $subject, $text);
            $em->flush();
            $mailer->sendAlways($player, $subject, $text);
            $this->addFlash('success', t('Danke! Dein Account ist jetzt erweitert.'));

            return new JsonResponse(['ok' => true]);
        } catch (PaymentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->trans($translator)], 422);
        }
    }

    #[Route('/erweitert/kuendigen', name: 'app_extended_cancel', methods: ['POST'])]
    #[IsCsrfTokenValid('extended', tokenKey: '_token')]
    public function cancel(): Response
    {
        $this->attempt(fn () => $this->paypal->cancel($this->player()), t('Abo gekündigt. Der bezahlte Zeitraum läuft noch bis zum Ende.'));

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
