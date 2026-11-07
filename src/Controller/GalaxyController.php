<?php

namespace App\Controller;

use App\Game\Universe;
use App\Repository\DebrisFieldRepository;
use App\Repository\PlanetRepository;
use App\Repository\TradePostRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GalaxyController extends AbstractGameController
{
    #[Route('/galaxy', name: 'app_galaxy', methods: ['GET'])]
    public function index(Request $request, PlanetRepository $planets, TradePostRepository $tradePosts, DebrisFieldRepository $debris): Response
    {
        $planet = $this->currentPlanet();
        $galaxy = max(1, min(Universe::GALAXIES, $request->query->getInt('galaxy', $planet->getGalaxy())));
        $system = max(1, min(Universe::SYSTEMS_PER_GALAXY, $request->query->getInt('system', $planet->getSystem())));

        // Fused planets occupy several consecutive slots.
        $occupied = [];
        foreach ($planets->findInSystem($galaxy, $system) as $p) {
            for ($pos = $p->getPosition(); $pos <= $p->getLastPosition(); ++$pos) {
                $occupied[$pos] = $p;
            }
        }

        $posts = [];
        foreach ($tradePosts->findInSystem($galaxy, $system) as $post) {
            $posts[$post->getPosition()] = $post;
        }

        $slots = [];
        for ($position = 1; $position <= Universe::planetsInSystem($galaxy, $system); ++$position) {
            $slots[$position] = $occupied[$position] ?? null;
        }

        return $this->renderGame('game/galaxy.html.twig', $planet, [
            'galaxy' => $galaxy,
            'system' => $system,
            'slots' => $slots,
            'posts' => $posts,
            'debris' => $debris->findInSystem($galaxy, $system),
            'galaxies' => Universe::GALAXIES,
            'systems' => Universe::SYSTEMS_PER_GALAXY,
        ]);
    }
}
