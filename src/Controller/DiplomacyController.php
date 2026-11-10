<?php

namespace App\Controller;

use App\Entity\Alliance;
use App\Entity\DiplomacyProposal;
use App\Entity\DiplomaticRelation;
use App\Game\Diplomacy;
use App\Game\GameException;
use App\Game\TreatyKind;
use App\Repository\AllianceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/** Diplomacy: bonds, non-aggression pacts and wars between alliances. */
class DiplomacyController extends AbstractGameController
{
    public function __construct(private readonly Diplomacy $diplomacy)
    {
    }

    #[Route('/diplomatie', name: 'app_diplomacy', methods: ['GET'])]
    public function index(AllianceRepository $alliances): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();
        $own = $player->getAlliance();

        return $this->renderGame('game/diplomacy.html.twig', $planet, [
            'own' => $own,
            'relations' => null === $own ? [] : $this->diplomacy->relationsOf($own),
            'proposals' => null === $own ? [] : $this->diplomacy->proposalsOf($own),
            'can_decide' => Diplomacy::canDecide($player),
            'voters' => null === $own ? [] : Diplomacy::voters($own),
            'others' => null === $own ? [] : array_values(array_filter($alliances->findBy([], ['name' => 'ASC']), static fn (Alliance $a): bool => $a->getId() !== $own->getId())),
            'kinds' => TreatyKind::cases(),
        ]);
    }

    #[Route('/diplomatie/vorschlag', name: 'app_diplomacy_propose', methods: ['POST'])]
    #[IsCsrfTokenValid('diplomacy', tokenKey: '_token')]
    public function propose(Request $request, AllianceRepository $alliances): Response
    {
        $this->currentPlanet();
        $id = $request->request->getString('alliance');
        $target = ctype_digit($id) ? $alliances->find((int) $id) : null;
        $kind = TreatyKind::tryFrom($request->request->getString('kind'));
        if (null === $target || null === $kind) {
            $this->addFlash('error', 'Wähle eine Allianz und eine Vertragsart.');
        } else {
            $this->attempt(fn () => $this->diplomacy->propose($this->player(), $target, $kind, $request->request->getString('message')), \sprintf('Vorschlag „%s“ mit %s eingereicht – deine Stimme ist gezählt.', $kind->label(), $target->getName()));
        }

        return $this->redirectToRoute('app_diplomacy');
    }

    #[Route('/diplomatie/beziehung/{id}/beenden', name: 'app_diplomacy_end', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('diplomacy', tokenKey: '_token')]
    public function end(DiplomaticRelation $relation, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->diplomacy->proposeEnd($this->player(), $relation, $request->request->getString('message')), 'Abstimmung über das Ende eingereicht – deine Stimme ist gezählt.');

        return $this->redirectToRoute('app_diplomacy');
    }

    #[Route('/diplomatie/abstimmung/{id}', name: 'app_diplomacy_vote', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('diplomacy', tokenKey: '_token')]
    public function vote(DiplomacyProposal $proposal, Request $request): Response
    {
        $this->currentPlanet();
        try {
            $result = $this->diplomacy->vote($this->player(), $proposal, 'yes' === $request->request->getString('vote'));
            $this->addFlash('success', $result);
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_diplomacy');
    }
}
