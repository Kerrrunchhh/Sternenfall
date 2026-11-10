<?php

namespace App\Controller;

use App\Entity\Fleet;
use App\Game\Espionage;
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
    public function index(Radar $radar, FleetRepository $fleets, Espionage $espionage, \App\Game\Diplomacy $diplomacy): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();
        $lifeForm = $player->getLifeForm();

        $own = array_values(array_filter(
            [...$fleets->findByOwner($player), ...$fleets->findControllableBy($player)],
            static fn (Fleet $f): bool => $f->isIdle() && $f->isNamed(),
        ));

        $visible = $radar->visibleFleets($player);
        $intel = $espionage->fleetIntel($player, $visible);
        $contacts = [];
        foreach ($visible as $fleet) {
            $attacking = FleetMission::Attack === $fleet->getMission() && !$fleet->isReturning();
            [$g, $s] = Radar::nextStop($fleet);
            $contacts[] = [
                'fleet' => $fleet,
                'stop' => implode(':', Radar::nextStop($fleet)),
                'seen_by' => $radar->coveringPlanets($player, $g, $s),
                'attacking' => $attacking,
                'ally' => !$diplomacy->mayAttack($player, $fleet->getOwner()),
                'can_intercept' => $attacking && \in_array($lifeForm, [LifeForm::Guardian, LifeForm::Pirate], true),
                'can_raid' => Radar::isRaidable($fleet) && LifeForm::Pirate === $lifeForm,
                'intel' => $intel[$fleet->getId()] ?? null,
            ];
        }

        $level = $player->getResearchLevel(ResearchType::Radar);

        return $this->renderGame('game/radar.html.twig', $planet, [
            'contacts' => $contacts,
            // Own fleets that give themselves away (attacking or carrying cargo): others with radar see them.
            'own_visible' => array_values(array_filter($fleets->findByOwner($player), static fn (Fleet $f): bool => Radar::emits($f))),
            'own_fleets' => $own,
            'radar_level' => $level,
            'radar_range' => Radar::range($level),
        ]);
    }

    #[Route('/radar/{id}/spy', name: 'app_radar_spy', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('radar', tokenKey: '_token')]
    public function spy(Fleet $target, Request $request): Response
    {
        $this->currentPlanet();
        try {
            $this->addFlash('success', $this->fleetService->spyFleet($this->player(), $target));
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->backTo($request, 'app_radar');
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
            $speed = \App\Game\Navigation::parsePercent($request->request->getString('speed'));
            $this->addFlash('success', $this->fleetService->hunt($hunter, $this->player(), $prey, FleetMission::from($mission), $speed));
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_radar');
    }
}
