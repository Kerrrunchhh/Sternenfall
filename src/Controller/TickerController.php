<?php

namespace App\Controller;

use App\Entity\Ticker;
use App\Entity\TickerMember;
use App\Entity\TickerPost;
use App\Game\GameException;
use App\Game\TickerService;
use App\Repository\TickerPostRepository;
use App\Repository\TickerRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

/** Long-range tickers: chat channels between players of any alliance. */
class TickerController extends AbstractGameController
{
    public function __construct(
        private readonly TickerService $service,
        private readonly TickerRepository $tickers,
        private readonly TickerPostRepository $posts,
    ) {
    }

    #[Route('/ticker', name: 'app_tickers', methods: ['GET'])]
    public function index(): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();

        return $this->renderGame('game/tickers.html.twig', $planet, [
            'tickers' => $this->tickers->findJoined($player),
            'invitations' => $this->tickers->findInvitations($player),
        ]);
    }

    #[Route('/ticker/{id}', name: 'app_ticker', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(Ticker $ticker): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();
        if (!$ticker->isMember($player)) {
            $this->addFlash('error', t('In diesem Ticker bist du nicht Mitglied.'));

            return $this->redirectToRoute('app_tickers');
        }

        return $this->renderGame('game/ticker.html.twig', $planet, [
            'ticker' => $ticker,
            'posts' => $this->posts->findLatest($ticker),
            'owner' => $ticker->isOwnedBy($player),
            'membership' => $ticker->membership($player),
        ]);
    }

    #[Route('/ticker/open', name: 'app_ticker_open', methods: ['POST'])]
    #[IsCsrfTokenValid('ticker', tokenKey: '_token')]
    public function open(Request $request): Response
    {
        $this->currentPlanet();
        try {
            $ticker = $this->service->open($this->player(), $request->request->getString('name'), $request->request->getString('description'));
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());

            return $this->redirectToRoute('app_tickers');
        }
        $this->addFlash('success', t('Ticker „{name}“ eröffnet. Lade jetzt Mitspieler ein.', ['{name}' => $ticker->getName()]));

        return $this->redirectToRoute('app_ticker', ['id' => $ticker->getId()]);
    }

    #[Route('/ticker/{id}/describe', name: 'app_ticker_describe', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('ticker', tokenKey: '_token')]
    public function describe(Ticker $ticker, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->service->describe($ticker, $this->player(), $request->request->getString('description')), t('Beschreibung gespeichert.'));

        return $this->redirectToRoute('app_ticker', ['id' => $ticker->getId()]);
    }

    #[Route('/ticker/{id}/post', name: 'app_ticker_post', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('ticker', tokenKey: '_token')]
    public function post(Ticker $ticker, Request $request): Response
    {
        $this->currentPlanet();
        try {
            $this->service->post($ticker, $this->player(), $request->request->getString('body'));
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirectToRoute('app_ticker', ['id' => $ticker->getId()]);
    }

    #[Route('/ticker/post/{id}/delete', name: 'app_ticker_post_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('ticker', tokenKey: '_token')]
    public function deletePost(TickerPost $post): Response
    {
        $this->currentPlanet();
        $ticker = $post->getTicker();
        $this->attempt(fn () => $this->service->deletePost($post, $this->player()), t('Beitrag gelöscht.'));

        return $this->redirectToRoute('app_ticker', ['id' => $ticker->getId()]);
    }

    #[Route('/ticker/{id}/invite', name: 'app_ticker_invite', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('ticker', tokenKey: '_token')]
    public function invite(Ticker $ticker, Request $request): Response
    {
        $this->currentPlanet();
        $name = trim($request->request->getString('username'));
        $this->attempt(fn () => $this->service->invite($ticker, $this->player(), $name), t('{name} wurde eingeladen.', ['{name}' => $name]));

        return $this->redirectToRoute('app_ticker', ['id' => $ticker->getId()]);
    }

    #[Route('/ticker/member/{id}/accept', name: 'app_ticker_accept', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('ticker', tokenKey: '_token')]
    public function accept(TickerMember $invitation): Response
    {
        $this->currentPlanet();
        $ticker = $invitation->getTicker();
        try {
            $this->service->accept($invitation, $this->player());
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());

            return $this->redirectToRoute('app_tickers');
        }
        $this->addFlash('success', t('Willkommen im Ticker „{name}“.', ['{name}' => $ticker->getName()]));

        return $this->redirectToRoute('app_ticker', ['id' => $ticker->getId()]);
    }

    /** Declines an invitation or leaves a ticker. */
    #[Route('/ticker/member/{id}/leave', name: 'app_ticker_leave', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('ticker', tokenKey: '_token')]
    public function leave(TickerMember $membership): Response
    {
        $this->currentPlanet();
        $message = $membership->isAccepted() ? t('Du hast den Ticker „{name}“ verlassen.', ['{name}' => $membership->getTicker()->getName()]) : 'Einladung abgelehnt.';
        $failed = false;
        try {
            $this->service->leave($membership, $this->player());
            $this->addFlash('success', $message);
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
            $failed = true;
        }

        return $failed ? $this->redirectToRoute('app_ticker', ['id' => $membership->getTicker()->getId()]) : $this->redirectToRoute('app_tickers');
    }

    #[Route('/ticker/member/{id}/remove', name: 'app_ticker_remove', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('ticker', tokenKey: '_token')]
    public function remove(TickerMember $member): Response
    {
        $this->currentPlanet();
        $ticker = $member->getTicker();
        $this->attempt(
            fn () => $this->service->remove($ticker, $this->player(), $member),
            $member->isAccepted() ? t('{player} wurde entfernt.', ['{player}' => $member->getUser()->getUsername()]) : 'Einladung zurückgezogen.',
        );

        return $this->redirectToRoute('app_ticker', ['id' => $ticker->getId()]);
    }

    #[Route('/ticker/member/{id}/hand-over', name: 'app_ticker_hand_over', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('ticker', tokenKey: '_token')]
    public function handOver(TickerMember $member): Response
    {
        $this->currentPlanet();
        $ticker = $member->getTicker();
        $this->attempt(fn () => $this->service->handOver($ticker, $this->player(), $member), t('{player} ist jetzt Besitzer des Tickers.', ['{player}' => $member->getUser()->getUsername()]));

        return $this->redirectToRoute('app_ticker', ['id' => $ticker->getId()]);
    }

    #[Route('/ticker/{id}/close', name: 'app_ticker_close', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('ticker', tokenKey: '_token')]
    public function close(Ticker $ticker): Response
    {
        $this->currentPlanet();
        $name = $ticker->getName();
        try {
            $this->service->close($ticker, $this->player());
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());

            return $this->redirectToRoute('app_ticker', ['id' => $ticker->getId()]);
        }
        $this->addFlash('success', t('Ticker „{name}“ geschlossen.', ['{name}' => $name]));

        return $this->redirectToRoute('app_tickers');
    }
}
