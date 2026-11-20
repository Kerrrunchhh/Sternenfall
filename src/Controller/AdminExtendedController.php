<?php

namespace App\Controller;

use App\Entity\User;
use App\Game\GameException;
use App\Game\Inbox;
use App\I18n\Lines;
use App\I18n\When;
use App\Payment\PayPalSubscriptions;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\Translation\t;

/** Admin: every extended account – who has one, until when, the PayPal subscription – and managing them. */
#[IsGranted('ROLE_ADMIN')]
class AdminExtendedController extends AbstractGameController
{
    /** Price of a month (also on the extended account page and in the PayPal plan). */
    public const float MONTHLY_PRICE = 5.0;
    /** Days an admin may grant at once. */
    public const int MAX_GIFT_DAYS = 366;

    #[Route('/admin/erweitert', name: 'app_admin_extended', methods: ['GET'])]
    public function index(UserRepository $users, ClockInterface $clock, PayPalSubscriptions $paypal): Response
    {
        $now = \DateTimeImmutable::createFromInterface($clock->now());
        $accounts = $users->createQueryBuilder('u')
            ->where('u.extendedUntil IS NOT NULL OR u.subscriptionId IS NOT NULL')
            ->orderBy('u.extendedUntil', 'DESC')->addOrderBy('u.username', 'ASC')
            ->getQuery()->getResult();
        $active = array_filter($accounts, static fn (User $u): bool => $u->isExtended($now));
        $subscribed = array_filter($accounts, static fn (User $u): bool => 'ACTIVE' === $u->getSubscriptionStatus());

        return $this->renderGame('game/admin_extended.html.twig', $this->currentPlanet(), [
            'accounts' => $accounts,
            'active_count' => \count($active),
            'subscribed_count' => \count($subscribed),
            'monthly_income' => \count($subscribed) * self::MONTHLY_PRICE,
            'max_gift_days' => self::MAX_GIFT_DAYS,
            'paypal_configured' => $paypal->isConfigured(),
        ]);
    }

    #[Route('/admin/erweitert/schenken', name: 'app_admin_extended_grant', methods: ['POST'])]
    #[IsCsrfTokenValid('admin_extended', tokenKey: '_token')]
    public function grantByName(Request $request, UserRepository $users, EntityManagerInterface $em, Inbox $inbox, ClockInterface $clock): Response
    {
        $this->currentPlanet();
        $name = trim($request->request->getString('username'));
        $user = $users->findOneBy(['username' => $name, 'system' => false]);
        try {
            if (null === $user) {
                throw new GameException(t('Keinen Spieler „{name}“ gefunden.', ['{name}' => $name]));
            }
            $this->grant($user, $request->request->getInt('days'), $em, $inbox, $clock);
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirectToRoute('app_admin_extended');
    }

    #[Route('/admin/erweitert/{id}', name: 'app_admin_extended_act', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('admin_extended', tokenKey: '_token')]
    public function act(User $user, Request $request, EntityManagerInterface $em, Inbox $inbox, ClockInterface $clock, PayPalSubscriptions $paypal): Response
    {
        $this->currentPlanet();
        $now = \DateTimeImmutable::createFromInterface($clock->now());
        try {
            switch ($request->request->getString('do')) {
                case 'grant':
                    $this->grant($user, $request->request->getInt('days'), $em, $inbox, $clock);
                    break;
                case 'end':
                    $user->endExtended($now);
                    $em->flush();
                    $this->addFlash('success', t('Der erweiterte Account von {player} ist beendet.', ['{player}' => $user->getUsername()])
                    );
                    break;
                case 'cancel':
                    $paypal->cancel($user);
                    $this->addFlash('success', t('Das Abo von {player} ist bei PayPal gekündigt; der bezahlte Zeitraum läuft bis {until}.', ['{player}' => $user->getUsername(), '{until}' => new When($user->getExtendedUntil() ?? $now)]));
                    break;
                case 'sync':
                    $status = $paypal->sync($user);
                    $this->addFlash('success', t('PayPal meldet für {player}: {status}.', ['{player}' => $user->getUsername(), '{status}' => $status]));
                    break;
                default:
                    throw new GameException(t('Unbekannte Aktion.'));
            }
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirect($this->generateUrl('app_admin_extended').'#account-'.$user->getId());
    }

    /** Extends the account by whole days (from now, or from its current end) and tells the player. */
    private function grant(User $user, int $days, EntityManagerInterface $em, Inbox $inbox, ClockInterface $clock): void
    {
        if ($days < 1 || $days > self::MAX_GIFT_DAYS) {
            throw new GameException(t('Gib 1 bis {max} Tage an.', ['{max}' => self::MAX_GIFT_DAYS]));
        }
        $now = \DateTimeImmutable::createFromInterface($clock->now());
        $from = $user->isExtended($now) ? $user->getExtendedUntil() : $now;
        $user->extendUntil($from->modify("+{$days} days"));
        $inbox->send($user, t('Erweiterter Account geschenkt'), new Lines([
            t('Die Spielleitung schenkt dir {days} Tage erweiterten Account – er läuft jetzt bis {until}. Viel Spaß!', ['{days}' => $days, '{until}' => new When($user->getExtendedUntil())]),
            t("\n– {player} (Spielleitung)", ['{player}' => $this->player()->getUsername()]),
        ]));
        $em->flush();
        $this->addFlash('success', t('{player} ist jetzt erweitert bis {until}.', ['{player}' => $user->getUsername(), '{until}' => new When($user->getExtendedUntil())]));
    }
}
