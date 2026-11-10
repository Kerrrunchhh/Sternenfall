<?php

namespace App\Controller;

use App\I18n\NumberInput;
use App\Entity\Alliance;
use App\Entity\AlliancePost;
use App\Entity\AllianceRequest;
use App\Entity\User;
use App\Game\AllianceRank;
use App\Game\AllianceService;
use App\Game\GameException;
use App\Repository\AllianceRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use App\I18n\Num;

use function Symfony\Component\Translation\t;

class AllianceController extends AbstractGameController
{
    public function __construct(
        private readonly AllianceService $alliances,
        private readonly AllianceRepository $repository,
    ) {
    }

    #[Route('/alliance', name: 'app_alliance', methods: ['GET'])]
    public function index(): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();
        $alliance = $player->getAlliance();

        if (null === $alliance) {
            return $this->renderGame('game/alliance_none.html.twig', $planet, [
                'alliances' => $this->repository->findAllByName(),
                'invitations' => $this->repository->findInvitationsFor($player),
            ]);
        }

        $rank = $player->getAllianceRank();

        return $this->renderGame('game/alliance.html.twig', $planet, [
            'alliance' => $alliance,
            'rank' => $rank,
            'posts' => $rank->canSeeBoard() ? $this->repository->findPosts($alliance) : [],
            'requests' => $rank->canManage() ? $this->repository->findRequests($alliance) : [],
            'assignable_ranks' => AllianceRank::assignable(),
            'election' => $this->alliances->election($alliance, $player),
        ]);
    }

    #[Route('/alliance/found', name: 'app_alliance_found', methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function found(Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(
            fn () => $this->alliances->found($this->player(), $request->request->getString('name'), $request->request->getString('tag')),
            t('{allianceType} „{value}“ wurde gegründet.', ['{allianceType}' => $this->player()->getLifeForm()->allianceType(), '{value}' => trim($request->request->getString('name'))]),
        );

        return $this->redirectToRoute('app_alliance');
    }

    #[Route('/alliance/{id}/apply', name: 'app_alliance_apply', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function apply(Alliance $alliance): Response
    {
        $this->currentPlanet();
        // Applying to an alliance that invited the player admits them at once.
        $invited = true === $this->repository->findRequest($alliance, $this->player())?->isInvitation();
        $this->attempt(
            fn () => $this->alliances->apply($this->player(), $alliance),
            $invited ? t('Du bist „{name}“ beigetreten.', ['{name}' => $alliance->getName()]) : t('Deine Bewerbung bei „{name}“ wurde abgeschickt.', ['{name}' => $alliance->getName()]),
        );

        return $invited && $this->player()->getAlliance()
            ? $this->redirectToRoute('app_alliance')
            : $this->redirectToRoute('app_alliance_public', ['id' => $alliance->getId()]);
    }

    #[Route('/alliance/request/{id}/{answer}', name: 'app_alliance_answer', requirements: ['id' => Requirement::DIGITS, 'answer' => 'accept|decline'], methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function answer(AllianceRequest $allianceRequest, string $answer): Response
    {
        $this->currentPlanet();
        'accept' === $answer
            ? $this->attempt(fn () => $this->alliances->accept($this->player(), $allianceRequest), t('Angenommen.'))
            : $this->attempt(fn () => $this->alliances->decline($this->player(), $allianceRequest), t('Abgelehnt.'));

        return $this->redirectToRoute('app_alliance');
    }

    #[Route('/alliance/invite', name: 'app_alliance_invite', methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function invite(Request $request): Response
    {
        $this->currentPlanet();
        $name = $request->request->getString('username');
        $this->attempt(fn () => $this->alliances->invite($this->player(), $name), t('{value} wurde eingeladen.', ['{value}' => trim($name)]));

        return $this->redirectToRoute('app_alliance');
    }

    #[Route('/alliance/member/{id}/rank', name: 'app_alliance_rank', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function rank(User $member, Request $request): Response
    {
        $this->currentPlanet();
        $rank = AllianceRank::tryFrom($request->request->getString('rank'));
        if ('founder' === $request->request->getString('rank')) {
            $this->attempt(fn () => $this->alliances->transferFounder($this->player(), $member), t('{player} ist jetzt Gründer.', ['{player}' => $member->getUsername()]));
        } elseif (null !== $rank) {
            $this->attempt(fn () => $this->alliances->setRank($this->player(), $member, $rank), t('{player} ist jetzt {rank}.', ['{player}' => $member->getUsername(), '{rank}' => t($rank->label())]));
        }

        return $this->redirectToRoute('app_alliance');
    }

    #[Route('/alliance/member/{id}/kick', name: 'app_alliance_kick', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function kick(User $member): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->alliances->kick($this->player(), $member), t('{player} wurde ausgeschlossen.', ['{player}' => $member->getUsername()]));

        return $this->redirectToRoute('app_alliance');
    }

    #[Route('/alliance/leave', name: 'app_alliance_leave', methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function leave(): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->alliances->leave($this->player()), t('Du hast die Allianz verlassen.'));

        return $this->redirectToRoute('app_alliance');
    }

    #[Route('/alliance/dissolve', name: 'app_alliance_dissolve', methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function dissolve(): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->alliances->dissolve($this->player()), t('Die Allianz wurde aufgelöst.'));

        return $this->redirectToRoute('app_alliance');
    }

    #[Route('/alliance/vote', name: 'app_alliance_vote', methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function vote(Request $request, UserRepository $users): Response
    {
        $this->currentPlanet();
        $candidate = $users->find($request->request->getInt('candidate'));
        try {
            $deposed = $this->alliances->voteFounder($this->player(), $candidate);
            $this->addFlash('success', match (true) {
                $deposed => t('Mehrheit erreicht: {player} ist jetzt Gründer.', ['{player}' => $candidate->getUsername()]),
                null === $candidate => 'Deine Stimme wurde zurückgezogen.',
                default => t('Deine Stimme für {player} wurde gezählt.', ['{player}' => $candidate->getUsername()]),
            });
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirectToRoute('app_alliance');
    }

    /** Public page of any alliance (picture, external text, apply button; no member list). */
    #[Route('/alliances/{id}', name: 'app_alliance_public', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(Alliance $alliance): Response
    {
        $planet = $this->currentPlanet();

        return $this->renderGame('game/alliance_public.html.twig', $planet, [
            'alliance' => $alliance,
            'request' => $this->repository->findRequest($alliance, $this->player()),
        ]);
    }

    #[Route('/alliance/steuer', name: 'app_alliance_tax', methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function tax(Request $request): Response
    {
        $this->currentPlanet();
        $rate = $request->request->getInt('rate');
        $this->attempt(fn () => $this->alliances->setTax($this->player(), $rate), t('Steuersatz: {rate} %.', ['{rate}' => $rate]));

        return $this->redirectToRoute('app_alliance');
    }

    #[Route('/alliance/auszahlung', name: 'app_alliance_payout', methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function payout(Request $request, \App\Repository\UserRepository $users): Response
    {
        $this->currentPlanet();
        $form = $request->request;
        $member = $users->find($form->getInt('member'));
        if (null === $member) {
            $this->addFlash('error', t('Wähle ein Mitglied aus.'));
        } else {
            $amount = NumberInput::amount($form->getString('amount'));
            $this->attempt(fn () => $this->alliances->payOut($this->player(), $member, $amount, $form->getString('note')), t('{value} Credits an {player} ausgezahlt.', ['{value}' => new Num(floor($amount)), '{player}' => $member->getUsername()]));
        }

        return $this->redirectToRoute('app_alliance');
    }

    #[Route('/alliance/presentation', name: 'app_alliance_present', methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function present(Request $request): Response
    {
        $this->currentPlanet();
        $form = $request->request;
        $this->attempt(
            fn () => $this->alliances->present($this->player(), $form->getString('internal_text'), $form->getString('external_text'), $request->files->get('image'), $form->getBoolean('remove_image')),
            t('Darstellung der Allianz gespeichert.'),
        );

        return $this->redirectToRoute('app_alliance');
    }

    #[Route('/alliance/board', name: 'app_alliance_post', methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function post(Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->alliances->post($this->player(), $request->request->getString('body')), t('Beitrag veröffentlicht.'));

        return $this->backTo($request, 'app_alliance');
    }

    #[Route('/alliance/board/{id}/delete', name: 'app_alliance_post_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('alliance', tokenKey: '_token')]
    public function deletePost(AlliancePost $post): Response
    {
        $this->currentPlanet();
        try {
            $this->alliances->deletePost($this->player(), $post);
            $this->addFlash('success', t('Beitrag gelöscht.'));
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirectToRoute('app_alliance');
    }
}
