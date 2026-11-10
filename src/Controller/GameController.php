<?php

namespace App\Controller;

use App\Entity\Fleet;
use App\Entity\Planet;
use App\Game\BuildingType;
use App\Game\ContactService;
use App\Game\Espionage;
use App\Game\Radar;
use App\Repository\FleetRepository;
use App\Repository\PlanetRepository;
use App\Game\FleetMission;
use App\Repository\ContactRepository;
use App\Repository\TradePostRepository;
use App\Game\QueueKind;
use App\Game\QueueTimeline;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

class GameController extends AbstractGameController
{
    #[Route('/overview', name: 'app_overview', methods: ['GET'])]
    public function overview(QueueTimeline $timeline, FleetRepository $fleets, Radar $radar, Espionage $espionage, TradePostRepository $tradePosts, ContactService $contacts, ContactRepository $contactRequests, PlanetRepository $planets): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();

        // Own fleets waiting at a trade post (shown at the very top, they want to trade).
        $atTradePosts = [];
        foreach ($fleets->findByOwner($player) as $fleet) {
            if ($fleet->isIdle() && null !== $post = $tradePosts->findAt($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition())) {
                $atTradePosts[] = ['fleet' => $fleet, 'post' => $post];
            }
        }

        // Fleet movements: own fleets in flight and foreign fleets heading here that the radar sees.
        $own = array_values(array_filter($fleets->findByOwner($player), static fn (Fleet $f): bool => $f->isMoving()));
        usort($own, static fn (Fleet $a, Fleet $b): int => $a->getNextEventAt() <=> $b->getNextEventAt());
        $incoming = array_values(array_filter($fleets->findIncoming($player), static fn (Fleet $f): bool => $radar->sees($player, $f)));

        return $this->renderGame('game/overview.html.twig', $planet, [
            'at_trade_posts' => $atTradePosts,
            'contacts' => $contacts->contactsOf($player),
            'incoming_requests' => $contactRequests->findIncomingRequests($player),
            'own_movements' => $own,
            'incoming' => array_values(array_filter($incoming, static fn (Fleet $f): bool => FleetMission::Attack !== $f->getMission())),
            // Attacks on own planets: red alarm at the top with origin, target, impact and a spy button.
            'attacks' => array_map(fn (Fleet $f): array => [
                'fleet' => $f,
                // A moving fleet keeps the coordinates it left from.
                'origin' => $planets->findAt($f->getGalaxy(), $f->getSystem(), $f->getPosition()),
                'target' => $planets->findAt((int) $f->getTargetGalaxy(), (int) $f->getTargetSystem(), (int) $f->getTargetPosition()),
            ], array_values(array_filter($incoming, static fn (Fleet $f): bool => FleetMission::Attack === $f->getMission()))),
            'incoming_intel' => $espionage->fleetIntel($player, $incoming),
            'building_queue' => $timeline->entries($planet, QueueKind::Buildings),
            'shipyard_queue' => $timeline->entries($planet, QueueKind::Shipyard),
            'defense_queue' => $timeline->entries($planet, QueueKind::Defense),
        ]);
    }

    #[Route('/buildings', name: 'app_buildings', methods: ['GET'])]
    public function buildings(QueueTimeline $timeline): Response
    {
        $planet = $this->currentPlanet();

        $buildings = [];
        foreach (BuildingType::cases() as $type) {
            // The next upgrade comes after everything already queued for this building.
            $nextLevel = $planet->getPlannedBuildingLevel($type) + 1;
            $buildings[] = [
                'type' => $type,
                'level' => $planet->getBuildingLevel($type),
                'planned' => $nextLevel - 1,
                'cost' => $this->economy->queuedBuildCost($type, $nextLevel, \count($planet->getBuildingQueue())),
                'seconds' => $this->economy->buildSeconds($type, $nextLevel, $planet),
                'missing' => $this->economy->missingRequirements($type->requirements(), $planet, planned: true),
                'affordable' => $planet->getResources()->covers($this->economy->queuedBuildCost($type, $nextLevel, \count($planet->getBuildingQueue()))),
            ];
        }

        return $this->renderGame('game/buildings.html.twig', $planet, [
            'buildings' => $buildings,
            'queue' => $timeline->entries($planet, QueueKind::Buildings),
            'queue_full' => \count($planet->getBuildingQueue()) >= QueueKind::Buildings->maxLength(),
            'surcharge' => [] !== $planet->getBuildingQueue(),
        ]);
    }

    #[Route('/buildings/{type}/build', name: 'app_build', requirements: ['type' => new EnumRequirement(BuildingType::class)], methods: ['POST'])]
    #[IsCsrfTokenValid('build', tokenKey: '_token')]
    public function build(BuildingType $type): Response
    {
        $planet = $this->currentPlanet();
        $this->attempt(fn () => $this->planetService->build($planet, $type), \sprintf('%s in die Bauschleife eingereiht.', $type->label()));

        return $this->redirectToRoute('app_buildings');
    }

    #[Route('/planet/{id}/rename', name: 'app_planet_rename', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('planet_rename', tokenKey: '_token')]
    public function renamePlanet(Planet $planet, Request $request): Response
    {
        $this->currentPlanet();
        $name = $request->request->getString('name');
        $this->attempt(fn () => $this->planetService->rename($planet, $this->player(), $name), \sprintf('Planet umbenannt in „%s“.', trim($name)));

        return $this->backTo($request, 'app_overview');
    }

    #[Route('/planet/{id}/abandon', name: 'app_planet_abandon', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('planet_abandon', tokenKey: '_token')]
    public function abandonPlanet(Planet $planet, Request $request): Response
    {
        $this->currentPlanet();
        if ('stay' === $request->request->getString('do')) {
            $this->attempt(fn () => $this->planetService->stayOn($planet, $this->player()), \sprintf('Du bleibst auf %s.', $planet->getName()));
        } else {
            try {
                $at = $this->planetService->abandon($planet, $this->player());
                $this->addFlash('success', \sprintf('%s wird am %s verlassen. Bring vorher Flotten, Schiffe und Rohstoffe in Sicherheit – oder brich ab.', $planet->getName(), $at->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i')));
            } catch (\App\Game\GameException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_overview');
    }

    #[Route('/planet/{id}/select', name: 'app_planet_select', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function selectPlanet(int $id, Request $request): Response
    {
        foreach ($this->player()->getPlanets() as $planet) {
            if ($planet->getId() === $id) {
                $request->getSession()->set(self::SESSION_PLANET, $id);
            }
        }

        return $this->backTo($request, 'app_overview');
    }
}
