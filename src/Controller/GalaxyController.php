<?php

namespace App\Controller;

use App\Game\Espionage;
use App\Game\Universe;
use App\Repository\DebrisFieldRepository;
use App\Repository\PlanetRepository;
use App\Repository\TradePostRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

class GalaxyController extends AbstractGameController
{
    /** Spy icon in the galaxy: the nearest scout in any own hangar flies off. */
    #[Route('/galaxy/spy', name: 'app_galaxy_spy', methods: ['POST'])]
    #[IsCsrfTokenValid('galaxy', tokenKey: '_token')]
    public function spy(Request $request): Response
    {
        $this->currentPlanet();
        $form = $request->request;
        [$galaxy, $system, $position] = [$form->getInt('galaxy'), $form->getInt('system'), $form->getInt('position')];
        try {
            $this->addFlash('success', $this->fleetService->quickSpy($this->player(), $galaxy, $system, $position));
        } catch (\App\Game\GameException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_galaxy', ['galaxy' => $galaxy, 'system' => $system]);
    }

    #[Route('/galaxy', name: 'app_galaxy', methods: ['GET'])]
    public function index(Request $request, PlanetRepository $planets, TradePostRepository $tradePosts, DebrisFieldRepository $debris, Espionage $espionage): Response
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

        // Planet types are hidden until the player sent a probe (own planets are always known).
        $known = $espionage->knownTypes($this->player(), $galaxy, $system);
        $slots = [];
        $types = [];
        for ($position = 1; $position <= Universe::planetsInSystem($galaxy, $system); ++$position) {
            $slots[$position] = $occupied[$position] ?? null;
            $types[$position] = $known[$position] ?? null;
        }

        return $this->renderGame('game/galaxy.html.twig', $planet, [
            'galaxy' => $galaxy,
            'system' => $system,
            'slots' => $slots,
            'types' => $types,
            'posts' => $posts,
            'debris' => $debris->findInSystem($galaxy, $system),
            'galaxies' => Universe::GALAXIES,
            'systems' => Universe::SYSTEMS_PER_GALAXY,
        ]);
    }
}
