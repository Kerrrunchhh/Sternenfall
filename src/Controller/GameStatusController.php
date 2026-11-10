<?php

namespace App\Controller;

use App\Game\GameStats;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The status page inside the game, in the normal game design. */
class GameStatusController extends AbstractGameController
{
    #[Route('/spiel/status', name: 'app_game_status', methods: ['GET'])]
    public function index(GameStats $stats): Response
    {
        return $this->renderGame('game/status.html.twig', $this->currentPlanet(), ['stats' => $stats->status(), 'top' => $stats->topExperienceToday()]);
    }
}
