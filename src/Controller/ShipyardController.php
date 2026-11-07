<?php

namespace App\Controller;

use App\Game\PlanetService;
use App\Game\QueueKind;
use App\Game\QueueTimeline;
use App\Game\ResourceType;
use App\Game\ShipType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

class ShipyardController extends AbstractGameController
{
    #[Route('/shipyard', name: 'app_shipyard', methods: ['GET'])]
    public function index(QueueTimeline $timeline): Response
    {
        $planet = $this->currentPlanet();
        $player = $planet->getOwner();
        $stock = $planet->getResources();

        $groups = ['Kampfschiffe' => [], 'Zivile Schiffe' => [], 'Verteidigung' => []];
        foreach (ShipType::cases() as $type) {
            $spec = $type->spec();

            $cost = $this->economy->shipCost($type, $player);
            $credits = $this->economy->shipCredits($type, $player);

            // Maximum affordable quantity, limited by every resource, credits and the available crew.
            $max = $spec->crew > 0 ? (int) floor($planet->getPopulation() / $spec->crew) : PlanetService::MAX_ORDER_QUANTITY;
            foreach (ResourceType::cases() as $resource) {
                if ($cost->get($resource) > 0) {
                    $max = min($max, (int) floor($stock->get($resource) / $cost->get($resource)));
                }
            }
            if ($credits > 0) {
                $max = min($max, (int) floor($player->getCredits() / $credits));
            }
            $max = min($max, PlanetService::MAX_ORDER_QUANTITY);

            $groups[match (true) {
                $type->isStationary() => 'Verteidigung',
                $type->isCombatShip() => 'Kampfschiffe',
                default => 'Zivile Schiffe',
            }][] = [
                'type' => $type,
                'spec' => $spec,
                'cost' => $cost,
                'credits' => $credits,
                'stationed' => $planet->getShipCount($type),
                'speed' => $this->economy->shipSpeed($type, $player),
                'seconds' => $this->economy->shipSeconds($type, $planet),
                'missing' => $this->economy->missingShipRequirements($type, $planet),
                'max' => $max,
            ];
        }

        return $this->renderGame('game/shipyard.html.twig', $planet, [
            'groups' => $groups,
            'queue' => $timeline->entries($planet, QueueKind::Shipyard),
        ]);
    }

    #[Route('/shipyard/{type}/order', name: 'app_shipyard_order', requirements: ['type' => new EnumRequirement(ShipType::class)], methods: ['POST'])]
    #[IsCsrfTokenValid('shipyard', tokenKey: '_token')]
    public function order(ShipType $type, Request $request): Response
    {
        $planet = $this->currentPlanet();
        $quantity = $request->request->getInt('quantity');
        $this->attempt(
            fn () => $this->planetService->orderShips($planet, $type, $quantity),
            \sprintf('%d × %s in Auftrag gegeben.', $quantity, $type->label()),
        );

        return $this->redirectToRoute('app_shipyard');
    }
}
