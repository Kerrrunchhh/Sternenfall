<?php

namespace App\Controller;

use App\Entity\User;
use App\Game\GameException;
use App\Game\MultiAccounts;
use App\Game\PushingGuard;
use App\Game\SupportService;
use App\Game\TicketKind;
use App\Repository\TicketRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

/**
 * Possible multi accounts and pushing for moderators and admins – nothing is blocked automatically.
 * The team can ask a player about it (anonymously, as "the support team"); he answers in the dialog.
 */
#[IsGranted('ROLE_MODERATOR')]
class AdminMultiController extends AbstractGameController
{
    #[Route('/admin/multis', name: 'app_admin_multis', methods: ['GET'])]
    public function index(MultiAccounts $multis, TicketRepository $tickets): Response
    {
        $pairs = $multis->suspiciousPairs();
        // Players already asked: their latest multi check, to open instead of asking twice.
        $asked = [];
        foreach ($pairs as $pair) {
            foreach ([$pair['a'], $pair['b']] as $player) {
                $asked[$player->getId()] ??= $tickets->findOneBy(['author' => $player, 'kind' => TicketKind::MultiCheck], ['createdAt' => 'DESC']);
            }
        }

        return $this->renderGame('game/admin_multis.html.twig', $this->currentPlanet(), [
            'pairs' => $pairs,
            'asked' => $asked,
            'gifts' => $multis->biggestGifts(),
            'keep_days' => MultiAccounts::KEEP_DAYS,
            'push_share' => (int) round(100 * PushingGuard::SHARE_OF_WEEKLY_PRODUCTION),
        ]);
    }

    /** The standard message, in the player's language, ready to be adjusted before it goes out. */
    #[Route('/admin/multis/anschreiben/{player}/{other}', name: 'app_admin_multi_ask', requirements: ['player' => Requirement::DIGITS, 'other' => Requirement::DIGITS], methods: ['GET'])]
    public function compose(User $player, User $other, TranslatorInterface $translator): Response
    {
        $locale = $player->getLocale();

        return $this->renderGame('game/admin_multi_ask.html.twig', $this->currentPlanet(), [
            'target' => $player,
            'other' => $other,
            'subject' => $translator->trans('Frage zu deinem Account', [], null, $locale),
            'body' => $translator->trans("Hallo {player},\n\nbei einer Routineprüfung ist uns aufgefallen, dass sich dein Account und ein anderer Account von derselben Internetverbindung oder demselben Gerät aus anmelden. Das kann harmlose Gründe haben, zum Beispiel Familie, Mitbewohner, ein gemeinsamer Computer oder eine Schule.\n\nIm Spiel darf jede Person nur einen Account haben, und Accounts dürfen sich nicht gegenseitig Vorteile verschaffen.\n\nBitte erkläre uns kurz, wie es dazu kommt. Antworte einfach hier – deine Antwort sehen nur wir.\n\nDanke!\nDein Support-Team", ['{player}' => $player->getUsername()], null, $locale),
        ]);
    }

    #[Route('/admin/multis/anschreiben/{player}/{other}', name: 'app_admin_multi_send', requirements: ['player' => Requirement::DIGITS, 'other' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('admin', tokenKey: '_token')]
    public function send(User $player, User $other, Request $request, SupportService $support): Response
    {
        $this->currentPlanet();
        try {
            $ticket = $support->askAboutMulti($player, $other, $request->request->getString('subject'), $request->request->getString('body'));
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());

            return $this->redirectToRoute('app_admin_multi_ask', ['player' => $player->getId(), 'other' => $other->getId()]);
        }
        $this->addFlash('success', t('Nachricht an {player} geschickt – als Support-Team, ohne deinen Namen.', ['{player}' => $player->getUsername()]));

        return $this->redirectToRoute('app_admin_ticket', ['id' => $ticket->getId()]);
    }
}
