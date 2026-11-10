<?php

namespace App\Controller;

use App\Game\ShipStats;
use App\Game\ShipType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;

/** Description of a ship or defense type with its values as the own research makes them. */
class ShipInfoController extends AbstractGameController
{
    #[Route('/schiffe/{type}', name: 'app_ship_info', requirements: ['type' => new EnumRequirement(ShipType::class)], methods: ['GET'])]
    public function show(ShipType $type, ShipStats $shipStats): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();

        return $this->renderGame('game/ship_info.html.twig', $planet, [
            'type' => $type,
            'spec' => $type->spec(),
            'stats' => $shipStats->describe($type, $player),
            'cost' => $this->economy->shipCost($type, $player),
            'credits' => $this->economy->shipCredits($type, $player),
            'seconds' => $this->economy->shipSeconds($type, $planet),
            'missing' => $this->economy->missingShipRequirements($type, $planet),
            'stationed' => $planet->getShipCount($type),
        ]);
    }
}
