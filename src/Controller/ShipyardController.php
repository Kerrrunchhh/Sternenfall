<?php

namespace App\Controller;

use App\Game\Economy;
use App\Game\PlanetService;
use App\Game\QueueKind;
use App\Game\QueueTimeline;
use App\Game\ResourceType;
use App\Game\ShipStats;
use App\Game\ShipType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use App\I18n\Num;

use function Symfony\Component\Translation\t;

class ShipyardController extends AbstractGameController
{
    /** Shipyard with two tabs: warships (default) and civil ships (?tab=civil). */
    #[Route('/shipyard', name: 'app_shipyard', methods: ['GET'])]
    public function index(QueueTimeline $timeline, ShipStats $shipStats, Request $request): Response
    {
        return $this->page($timeline, $shipStats, defense: false, civil: 'civil' === $request->query->getString('tab'));
    }

    /** Planetary defense: the turrets, built in the same shipyard queue. */
    #[Route('/defense', name: 'app_defense', methods: ['GET'])]
    public function defense(QueueTimeline $timeline, ShipStats $shipStats): Response
    {
        return $this->page($timeline, $shipStats, defense: true);
    }

    private function page(QueueTimeline $timeline, ShipStats $shipStats, bool $defense, bool $civil = false): Response
    {
        $planet = $this->currentPlanet();
        $player = $planet->getOwner();
        $stock = $planet->getResources();

        $groups = match (true) {
            $defense => ['Verteidigungsanlagen' => []],
            $civil => ['Zivile Schiffe' => []],
            default => ['Kriegsschiffe' => []],
        };
        foreach (ShipType::cases() as $type) {
            if ($type->isStationary() !== $defense || (!$defense && $type->isCombatShip() === $civil)) {
                continue;
            }
            $spec = $type->spec();

            // One variant per build rate: cost, credits, time and the affordable quantity.
            $rates = [];
            foreach (Economy::BUILD_PERCENTS as $percent) {
                $cost = $this->economy->shipOrderCost($type, $player, $percent);
                $credits = $this->economy->shipOrderCredits($type, $player, $percent);

                // Maximum affordable quantity, limited by every resource, credits and the available crew.
                $max = $spec->crew > 0 ? (int) floor($planet->getPopulation() / $spec->crew) : PlanetService::maxOrderQuantity($type);
                foreach (ResourceType::cases() as $resource) {
                    if ($cost->get($resource) > 0) {
                        $max = min($max, (int) floor($stock->get($resource) / $cost->get($resource)));
                    }
                }
                if ($credits > 0) {
                    $max = min($max, (int) floor($player->getCredits() / $credits));
                }
                $rates[$percent] = [
                    'cost' => $cost,
                    'credits' => $credits,
                    'seconds' => $this->economy->shipOrderSeconds($type, $planet, $percent, \DateTimeImmutable::createFromInterface($this->clock->now())),
                    'max' => min($max, PlanetService::maxOrderQuantity($type)),
                ];
            }

            $groups[match (true) {
                $type->isStationary() => 'Verteidigungsanlagen',
                $type->isCombatShip() => 'Kriegsschiffe',
                default => 'Zivile Schiffe',
            }][] = [
                'type' => $type,
                'spec' => $spec,
                'rates' => $rates,
                'stationed' => $planet->getShipCount($type),
                'stats' => $shipStats->describe($type, $player),
                'missing' => $this->economy->missingShipRequirements($type, $planet),
            ];
        }

        return $this->renderGame('game/shipyard.html.twig', $planet, [
            'defense' => $defense,
            'civil' => $civil,
            'groups' => $groups,
            'queue' => $timeline->entries($planet, $defense ? QueueKind::Defense : QueueKind::Shipyard),
        ]);
    }

    #[Route('/shipyard/{type}/order', name: 'app_shipyard_order', requirements: ['type' => new EnumRequirement(ShipType::class)], methods: ['POST'])]
    #[IsCsrfTokenValid('shipyard', tokenKey: '_token')]
    public function order(ShipType $type, Request $request): Response
    {
        $planet = $this->currentPlanet();
        $quantity = $request->request->getInt('quantity');
        $percent = $request->request->getInt('percent', 100);
        $this->attempt(
            fn () => $this->planetService->orderShips($planet, $type, $quantity, $percent),
            t('{quantity} × {type} in Auftrag gegeben{value}.', ['{quantity}' => new Num($quantity), '{type}' => t($type->label()), '{value}' => 100 === $percent ? '' : " ($percent %)"]),
        );

        return match (true) {
            $type->isStationary() => $this->redirectToRoute('app_defense'),
            $type->isCombatShip() => $this->redirectToRoute('app_shipyard'),
            default => $this->redirectToRoute('app_shipyard', ['tab' => 'civil']),
        };
    }
}
