<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Game\GameException;
use App\Game\SupportService;
use App\Game\TicketKind;
use App\Repository\TicketRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

/** "Hilfe & Meldungen": report a player, make a suggestion, report a bug. */
class SupportController extends AbstractGameController
{
    public function __construct(
        private readonly SupportService $support,
        private readonly TicketRepository $tickets,
    ) {
    }

    #[Route('/hilfe', name: 'app_support', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderGame('game/support.html.twig', $this->currentPlanet(), [
            'tickets' => $this->tickets->findByAuthor($this->player()),
            'kinds' => TicketKind::cases(),
            'kind' => TicketKind::tryFrom($request->query->getString('kind')) ?? TicketKind::Suggestion,
            'reported' => $request->query->getString('report'),
        ]);
    }

    #[Route('/hilfe/neu', name: 'app_support_create', methods: ['POST'])]
    #[IsCsrfTokenValid('support', tokenKey: '_token')]
    public function create(Request $request): Response
    {
        $this->currentPlanet();
        $form = $request->request;
        $kind = TicketKind::tryFrom($form->getString('kind')) ?? TicketKind::Suggestion;
        try {
            $ticket = $this->support->create($this->player(), $kind, $form->getString('subject'), $form->getString('body'), $form->getString('reported'));
            $this->addFlash('success', t('Danke! Deine Meldung ist bei den Admins angekommen.'));

            return $this->redirectToRoute('app_support_show', ['id' => $ticket->getId()]);
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());

            return $this->redirectToRoute('app_support', ['kind' => $kind->value, 'report' => $form->getString('reported')]);
        }
    }

    #[Route('/hilfe/{id}', name: 'app_support_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(Ticket $ticket): Response
    {
        $planet = $this->currentPlanet();
        if (!$ticket->isByAuthor($this->player())) {
            throw $this->createNotFoundException();
        }

        return $this->renderGame('game/support_show.html.twig', $planet, ['ticket' => $ticket, 'admin_view' => false]);
    }

    #[Route('/hilfe/{id}/antwort', name: 'app_support_reply', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('support', tokenKey: '_token')]
    public function reply(Ticket $ticket, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->support->reply($ticket, $this->player(), $request->request->getString('body')), t('Antwort gesendet.'));

        return $this->redirectToRoute('app_support_show', ['id' => $ticket->getId()]);
    }
}
