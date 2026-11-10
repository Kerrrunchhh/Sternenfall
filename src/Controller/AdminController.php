<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Game\GameException;
use App\Game\SupportService;
use App\Game\TicketKind;
use App\Game\TicketStatus;
use App\Game\VacationService;
use App\Repository\TicketRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Admin area: reports, suggestions and bug reports. */
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractGameController
{
    public function __construct(
        private readonly SupportService $support,
        private readonly TicketRepository $tickets,
    ) {
    }

    #[Route('/admin/meldungen', name: 'app_admin_tickets', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $kind = TicketKind::tryFrom($request->query->getString('kind'));
        $all = 'all' === $request->query->getString('show');

        return $this->renderGame('game/admin_tickets.html.twig', $this->currentPlanet(), [
            'tickets' => $this->tickets->findForAdmins($kind, !$all),
            'kind' => $kind,
            'all' => $all,
            'kinds' => TicketKind::cases(),
        ]);
    }

    #[Route('/admin/meldungen/{id}', name: 'app_admin_ticket', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(Ticket $ticket): Response
    {
        return $this->renderGame('game/support_show.html.twig', $this->currentPlanet(), [
            'ticket' => $ticket,
            'admin_view' => true,
            'statuses' => TicketStatus::cases(),
        ]);
    }

    #[Route('/admin/meldungen/{id}/antwort', name: 'app_admin_ticket_answer', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('admin', tokenKey: '_token')]
    public function answer(Ticket $ticket, Request $request): Response
    {
        $this->currentPlanet();
        $status = TicketStatus::tryFrom($request->request->getString('status')) ?? $ticket->getStatus();
        $this->attempt(fn () => $this->support->answer($ticket, $this->player(), $request->request->getString('body'), $status), 'Gespeichert, der Spieler wurde benachrichtigt.');

        return $this->redirectToRoute('app_admin_ticket', ['id' => $ticket->getId()]);
    }

    #[Route('/admin/meldungen/{id}/weiterleiten', name: 'app_admin_ticket_forward', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('admin', tokenKey: '_token')]
    public function forwardTicket(Ticket $ticket, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->support->forward($ticket, $this->player(), $request->request->getString('note')), 'An den Entwickler weitergeleitet.');

        return $this->redirectToRoute('app_admin_ticket', ['id' => $ticket->getId()]);
    }

    #[Route('/admin/meldungen/{id}/strafe', name: 'app_admin_ticket_penalty', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('admin', tokenKey: '_token')]
    public function penalty(Ticket $ticket, Request $request): Response
    {
        $this->currentPlanet();
        try {
            $until = $this->support->penalize($ticket, $this->player(), VacationService::parseDays($request->request->getString('days')), $request->request->getString('reason'));
            $this->addFlash('success', \sprintf('Strafe verhängt: %s ist bis %s im Urlaub.', $ticket->getReported()?->getUsername(), $until->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i')));
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_ticket', ['id' => $ticket->getId()]);
    }
}
