<?php

namespace App\Controller;

use App\Entity\Poll;
use App\Game\Poll\PollForm;
use App\Game\Poll\VoteForm;
use App\Game\PollService;
use App\Repository\PollRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\Translation\t;

/**
 * Polls: players vote (once, with a reason if they like) and see the result when it is over;
 * admins create them and see every vote with its name all along.
 */
class PollController extends AbstractGameController
{
    public function __construct(
        private readonly PollRepository $polls,
        private readonly PollService $service,
        private readonly ClockInterface $pollClock,
    ) {
    }

    #[Route('/umfragen', name: 'app_polls', methods: ['GET'])]
    public function index(): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();
        $admin = $this->isGranted('ROLE_ADMIN');
        $now = \DateTimeImmutable::createFromInterface($this->pollClock->now());

        $entries = [];
        foreach ($this->polls->findLatest() as $poll) {
            $running = $poll->isRunning($now);
            // Results only when it is over – admins see them all along.
            $showResult = !$running || $admin;
            $votes = $showResult ? $this->polls->votesOf($poll) : [];
            $entries[] = [
                'poll' => $poll,
                'running' => $running,
                'mine' => $this->polls->voteOf($poll, $player),
                'result' => $showResult ? PollService::result($poll, $votes) : null,
                // Who voted what – for admins only.
                'votes' => $admin ? $votes : [],
            ];
        }

        return $this->renderGame('game/polls.html.twig', $planet, [
            'entries' => $entries,
            'is_admin' => $admin,
            'max_reason' => \App\Entity\PollVote::MAX_REASON,
        ]);
    }

    #[Route('/umfragen/{id}/abstimmen', name: 'app_poll_vote', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('poll', tokenKey: '_token')]
    public function vote(Poll $poll, #[MapRequestPayload] VoteForm $form): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->service->vote($poll, $this->player(), $form->choices, $form->reason), t('Danke für deine Stimme! Das Ergebnis siehst du hier, sobald die Umfrage beendet ist.'));

        return $this->redirectToRoute('app_polls', ['_fragment' => 'umfrage-'.$poll->getId()]);
    }

    #[Route('/umfragen/neu', name: 'app_poll_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid('poll', tokenKey: '_token')]
    public function create(#[MapRequestPayload] PollForm $form): Response
    {
        $this->currentPlanet();
        try {
            $poll = $this->service->create($this->player(), $form->question, $form->optionList(), $form->multiple, $form->totalHours(), $form->questionEn, $form->optionListEn());
            $mailed = $form->mail ? $this->service->mailAnnouncement($poll) : null;
            $this->addFlash('success', null === $mailed
                ? t('Umfrage gestartet – alle Spieler haben eine Nachricht bekommen.')
                : t('Umfrage gestartet – alle Spieler haben eine Nachricht bekommen, {count} zusätzlich eine E-Mail.', ['{count}' => $mailed]));
        } catch (\App\Game\GameException $e) {
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirectToRoute('app_polls');
    }

    #[Route('/umfragen/{id}/beenden', name: 'app_poll_close', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid('poll', tokenKey: '_token')]
    public function close(Poll $poll): Response
    {
        $this->currentPlanet();
        $this->service->close($poll);
        $this->addFlash('success', t('Umfrage beendet – alle sehen jetzt das Ergebnis.'));

        return $this->redirectToRoute('app_polls', ['_fragment' => 'umfrage-'.$poll->getId()]);
    }
}
