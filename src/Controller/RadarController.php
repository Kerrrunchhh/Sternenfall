<?php

namespace App\Controller;

use App\Entity\Fleet;
use App\Game\FleetMission;
use App\Game\GameException;
use App\Game\LifeForm;
use App\Game\Radar;
use App\Game\ResearchType;
use App\Repository\FleetRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

class RadarController extends AbstractGameController
{
    #[Route('/radar', name: 'app_radar', methods: ['GET'])]
    public function index(Radar $radar, FleetRepository $fleets): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();
        $lifeForm = $player->getLifeForm();

        $own = array_values(array_filter(
            [...$fleets->findByOwner($player), ...$fleets->findControllableBy($player)],
            static fn (Fleet $f): bool => $f->isIdle() && $f->isNamed(),
        ));

        $contacts = [];
        foreach ($radar->visibleFleets($player) as $fleet) {
            $attacking = FleetMission::Attack === $fleet->getMission() && !$fleet->isReturning();
            [$g, $s] = Radar::nextStop($fleet);
            $contacts[] = [
                'fleet' => $fleet,
                'stop' => implode(':', Radar::nextStop($fleet)),
                'seen_by' => $radar->coveringPlanets($player, $g, $s),
                'attacking' => $attacking,
                'ally' => $player->isAllyOf($fleet->getOwner()),
                'can_intercept' => $attacking && \in_array($lifeForm, [LifeForm::Guardian, LifeForm::Pirate], true),
                'can_raid' => $fleet->getCargo()->total() >= 1 && LifeForm::Pirate === $lifeForm,
                'ships' => array_sum($fleet->getShips()),
            ];
        }

        $level = $player->getResearchLevel(ResearchType::Radar);

        return $this->renderGame('game/radar.html.twig', $planet, [
            'contacts' => $contacts,
            'own_fleets' => $own,
            'radar_level' => $level,
            'radar_range' => Radar::range($level),
        ]);
    }

    #[Route('/radar/{id}/{mission}', name: 'app_radar_hunt', requirements: ['id' => Requirement::DIGITS, 'mission' => 'intercept|raid'], methods: ['POST'])]
    #[IsCsrfTokenValid('radar', tokenKey: '_token')]
    public function hunt(Fleet $prey, string $mission, Request $request, FleetRepository $fleets): Response
    {
        $this->currentPlanet();
        $hunter = $fleets->find($request->request->getInt('fleet'));
        try {
            if (null === $hunter) {
                throw new GameException('Bitte wähle eine eigene Flotte aus.');
            }
            $this->addFlash('success', $this->fleetService->hunt($hunter, $this->player(), $prey, FleetMission::from($mission)));
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_radar');
    }
}
