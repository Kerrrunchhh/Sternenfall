<?php

namespace App\Controller;

use App\Game\Espionage;
use App\Game\Universe;
use App\Repository\BookmarkRepository;
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
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirectToRoute('app_galaxy', ['galaxy' => $galaxy, 'system' => $system]);
    }

    /** "Kolonisieren" in the galaxy: a colony ship leaves the hangar of the planet being looked at. */
    #[Route('/galaxy/colonize', name: 'app_galaxy_colonize', methods: ['POST'])]
    #[IsCsrfTokenValid('galaxy', tokenKey: '_token')]
    public function colonize(Request $request): Response
    {
        $planet = $this->currentPlanet();
        $form = $request->request;
        [$galaxy, $system, $position] = [$form->getInt('galaxy'), $form->getInt('system'), $form->getInt('position')];
        try {
            $this->addFlash('success', $this->fleetService->quickColonize($planet, $galaxy, $system, $position));
        } catch (\App\Game\GameException $e) {
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirect($this->generateUrl('app_galaxy', ['galaxy' => $galaxy, 'system' => $system]).'#slot-'.$position);
    }

    #[Route('/galaxy', name: 'app_galaxy', methods: ['GET'])]
    public function index(Request $request, PlanetRepository $planets, TradePostRepository $tradePosts, Espionage $espionage, BookmarkRepository $bookmarks): Response
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
        // A known fusion shows as what it is: a blue planet.
        foreach ($occupied as $position => $p) {
            if ($p->isFused() && $position === $p->getPosition() && isset($known[$position])) {
                $known[$position] = $p->getType();
            }
        }
        for ($position = 1; $position <= Universe::planetsInSystem($galaxy, $system); ++$position) {
            $slots[$position] = $occupied[$position] ?? null;
            $types[$position] = $known[$position] ?? null;
        }

        // The system view: the sun and every slot circling it (see Orbit, public/js/game.js).
        $player = $this->player();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $orbits = [];
        foreach ($slots as $position => $p) {
            $orbits[] = [
                'position' => $position,
                'name' => $p?->getName(),
                'owner' => $p?->getOwner()->getUsername(),
                'own' => null !== $p && $p->getOwner()->isSameAs($player),
                'criminal' => null !== $p && $p->getOwner()->isCriminal(),
                'inactive' => null !== $p && !$p->getOwner()->isSameAs($player) && $p->getOwner()->isInactive($now),
                'vacation' => null !== $p && $p->getOwner()->isOnVacation($now),
                // The further slots of a fusion circle with its first one.
                'fusedInto' => null !== $p && $p->getPosition() !== $position ? $p->getPosition() : null,
                'post' => null === $p && isset($posts[$position]),
            ];
        }
        $sun = \App\Game\SunType::of($galaxy, $system);

        return $this->renderGame('game/galaxy.html.twig', $planet, [
            'system_view' => [
                'galaxy' => $galaxy,
                'system' => $system,
                'slots' => $orbits,
                'sun' => ['color' => $sun->color(), 'size' => $sun->size(), 'binary' => \App\Game\SunType::BinaryStar === $sun],
                // Distances from the chosen planet, if it lies in this system.
                'origin' => $planet->getGalaxy() === $galaxy && $planet->getSystem() === $system ? $planet->getPosition() : null,
                'originName' => $planet->getName(),
                'orbit' => ['epoch' => \App\Game\Orbit::EPOCH, 'inner' => \App\Game\Orbit::INNER_PERIOD_DAYS, 'outer' => \App\Game\Orbit::OUTER_PERIOD_DAYS, 'base' => \App\Game\Orbit::RADIUS_BASE, 'step' => \App\Game\Orbit::RADIUS_STEP, 'travel' => \App\Game\Orbit::TRAVEL_BASE],
            ],
            'sun' => $sun,
            'bookmarks' => $bookmarks->findInSystem($this->player(), $galaxy, $system),
            'galaxy' => $galaxy,
            'system' => $system,
            'slots' => $slots,
            'types' => $types,
            'posts' => $posts,
            // Debris fields only where the player discovered them (probe, battle, salvaging).
            'debris' => $espionage->knownDebris($this->player(), $galaxy, $system),
            'galaxies' => Universe::GALAXIES,
            'systems' => Universe::SYSTEMS_PER_GALAXY,
        ]);
    }
}
