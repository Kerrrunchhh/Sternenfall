<?php

namespace App\Controller;

use App\Game\Diplomacy;
use App\Game\Espionage;
use App\Game\TreatyKind;
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
    /** Spy icon in the galaxy (and in a player's profile): the nearest scout in any own hangar flies off. */
    #[Route('/galaxy/spy', name: 'app_galaxy_spy', methods: ['POST'])]
    #[IsCsrfTokenValid('galaxy', tokenKey: '_token')]
    public function spy(Request $request, \Symfony\Contracts\Translation\TranslatorInterface $translator): Response
    {
        $this->currentPlanet();
        $form = $request->request;
        [$galaxy, $system, $position] = [$form->getInt('galaxy'), $form->getInt('system'), $form->getInt('position')];
        try {
            [$ok, $message] = [true, $this->fleetService->quickSpy($this->player(), $galaxy, $system, $position)];
        } catch (\App\Game\GameException $e) {
            [$ok, $message] = [false, $e->translatable()];
        }

        // Sent by game.js: answer in place, the page stays as it is (no reload).
        if ($request->isXmlHttpRequest()) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['ok' => $ok, 'message' => $message->trans($translator)], $ok ? 200 : 422);
        }
        $this->addFlash($ok ? 'success' : 'error', $message);

        // From a player's profile: back to his planet list.
        if (($profile = $form->getInt('profile')) > 0) {
            return $this->redirect($this->generateUrl('app_player', ['id' => $profile]).'#planets');
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
    public function index(Request $request, PlanetRepository $planets, TradePostRepository $tradePosts, Espionage $espionage, BookmarkRepository $bookmarks, Diplomacy $diplomacy): Response
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
        // Who a planet belongs to, seen from the player: own, own alliance, bond or NAP partner, war enemy.
        $relations = [];
        foreach ($slots as $position => $p) {
            if (null !== $p) {
                $relations[$position] = self::relation($player, $p->getOwner(), $diplomacy);
            }
        }
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
                // A trade post (they stand on planets): marked in the view.
                'post' => isset($posts[$position]),
                'relation' => $relations[$position] ?? null,
                'alliance' => $p?->getOwner()->getAlliance()?->getTag(),
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
            'relations' => $relations,
            'galaxies' => Universe::GALAXIES,
            'systems' => Universe::SYSTEMS_PER_GALAXY,
            // The galaxy map: every system on the spiral arms, turning with the galaxy (see Orbit, public/js/game.js).
            'galaxy_map' => [
                'galaxy' => $galaxy,
                'system' => $system,
                'systems' => Universe::SYSTEMS_PER_GALAXY,
                'occupied' => $planets->systemsInGalaxy($galaxy),
                'own' => $planets->systemsInGalaxy($galaxy, [$player]),
                'alliance' => null === $player->getAlliance() ? [] : $planets->systemsInGalaxy($galaxy, array_values(array_filter(
                    $player->getAlliance()->getMembers()->toArray(),
                    static fn (\App\Entity\User $member): bool => !$member->isSameAs($player),
                ))),
                'guild' => \App\Game\TradeGuild::SYSTEM,
                'url' => $this->generateUrl('app_galaxy', ['galaxy' => $galaxy]),
            ],
            // The universe map: the galaxies circling the centre of the universe (see Orbit, public/js/game.js).
            'universe_map' => [
                'galaxy' => $galaxy,
                'galaxies' => Universe::GALAXIES,
                'own' => array_values(array_unique(array_map(static fn (\App\Entity\Planet $p): int => $p->getGalaxy(), $player->getPlanets()->toArray()))),
                'periods' => array_map(static fn (int $g): int => (int) round(\App\Game\Orbit::galaxyPeriodSeconds($g) / 86400), range(1, Universe::GALAXIES)),
                'url' => $this->generateUrl('app_galaxy'),
            ],
            'galaxy_orbit_days' => (int) round(\App\Game\Orbit::galaxyPeriodSeconds($galaxy) / 86400),
            'galaxy_spin_days' => (int) \App\Game\Orbit::GALAXY_SPIN_DAYS,
        ]);
    }

    /** own, alliance (same alliance), ally (bond or NAP), war – or null for everybody else. */
    private static function relation(\App\Entity\User $player, \App\Entity\User $owner, Diplomacy $diplomacy): ?string
    {
        return match (true) {
            $owner->isSameAs($player) => 'own',
            $owner->isAllyOf($player) => 'alliance',
            default => match ($diplomacy->kindBetween($player, $owner)) {
                TreatyKind::Bond, TreatyKind::Nap => 'ally',
                TreatyKind::War => 'war',
                null => null,
            },
        };
    }
}
