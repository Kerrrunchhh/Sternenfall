<?php

namespace App\Controller;

use App\Entity\Fleet;
use App\Game\FleetMission;
use App\Game\Navigation;
use App\Game\Radar;
use App\Game\GameException;
use App\Game\Resources;
use App\Game\ResourceType;
use App\Game\ShipType;
use App\Repository\BookmarkRepository;
use App\Repository\FleetRepository;
use App\Repository\PlanetRepository;
use App\Repository\UserRepository;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

class FleetController extends AbstractGameController
{
    #[Route('/fleet', name: 'app_fleet', methods: ['GET'])]
    public function index(Request $request, FleetRepository $fleets, PlanetRepository $planets, Radar $radar, Navigation $navigation, BookmarkRepository $bookmarks): Response
    {
        $planet = $this->currentPlanet();
        $player = $planet->getOwner();

        $hangar = [];
        foreach (ShipType::cases() as $type) {
            if ($planet->getShipCount($type) > 0 && !$type->isStationary() && !$type->isProbe()) {
                $hangar[] = ['type' => $type, 'count' => $planet->getShipCount($type), 'speed' => $this->economy->shipSpeed($type, $player)];
            }
        }

        // Where each fleet is: own planets allow loading, unloading and dissolving.
        $entries = [];
        $hoverSources = $this->fleetService->hoverSources($player);
        foreach ($fleets->findByOwner($player) as $fleet) {
            $at = $planets->findAt($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition());
            $hover = [];
            if ($fleet->isIdle() && $fleet->isNamed()) {
                foreach ($hoverSources as $key => $source) {
                    $hover[] = ['key' => $key, 'label' => $source['label'], 'seconds' => $this->fleetService->hoverSeconds($fleet, $source)];
                }
            }
            $entries[] = [
                'own' => true,
                'speed' => $navigation->slowestSpeed($fleet->getShips(), $player),
                'hover' => $hover,
                'fleet' => $fleet,
                'at' => $at,
                'base' => null !== $at && $at->getOwner()->getId() === $player->getId() ? $at : null,
                'capacity' => array_sum(array_map(static fn (array $s): int => $s['type']->cargo() * $s['count'], $fleet->getShipList())),
            ];
        }

        // Fleets of alliance mates released to this player.
        $shared = [];
        foreach ($fleets->findControllableBy($player) as $fleet) {
            $at = $planets->findAt($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition());
            $shared[] = [
                'own' => false,
                'hover' => [],
                'capacity' => 0,
                'speed' => $navigation->slowestSpeed($fleet->getShips(), $fleet->getOwner()),
                'fleet' => $fleet,
                'at' => $at,
                'base' => null !== $at && $at->getOwner()->isSameAs($fleet->getOwner()) ? $at : null,
            ];
        }

        $allies = null === $player->getAlliance() ? [] : array_values(array_filter(
            $player->getAlliance()->getMembers()->toArray(),
            static fn (User $member): bool => !$member->isSameAs($player),
        ));

        return $this->renderGame('game/fleet.html.twig', $planet, [
            'bulk_actions' => self::BULK_ACTIONS,
            'hover_sources' => $hoverSources,
            'bookmarks' => $bookmarks->findByOwner($player),
            'game_speed' => $this->economy->speed(),
            'hangar' => $hangar,
            'entries' => $entries,
            'shared' => $shared,
            'allies' => $allies,
            'missions' => FleetMission::selectable(),
            'ship_types' => ShipType::cases(),
            // Foreign fleets heading here are only known if the radar sees them.
            'incoming' => array_values(array_filter($fleets->findIncoming($player), static fn (Fleet $f): bool => $radar->sees($player, $f))),
            'target' => [
                'galaxy' => $request->query->getInt('galaxy', $planet->getGalaxy()),
                'system' => $request->query->getInt('system', $planet->getSystem()),
                'position' => $request->query->getInt('position', $planet->getPosition()),
                // Preselected in the action dropdown when coming from the universe view.
                'mission' => FleetMission::tryFrom($request->query->getString('mission')),
            ],
        ]);
    }

    #[Route('/fleets/create', name: 'app_fleets_create', methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function create(Request $request): Response
    {
        $planet = $this->currentPlanet();
        $name = $request->request->getString('name');
        $this->attempt(fn () => $this->fleetService->create($planet, $name, $this->ships($request)), \sprintf('Flotte „%s“ gebildet.', trim($name)));

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleets/{id}/rename', name: 'app_fleets_rename', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function rename(Fleet $fleet, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->fleetService->rename($fleet, $this->player(), $request->request->getString('name')), 'Flotte umbenannt.');

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleets/{id}/send', name: 'app_fleets_send', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function sendFleet(Fleet $fleet, Request $request): Response
    {
        $this->currentPlanet();
        $form = $request->request;
        $mission = FleetMission::tryFrom($form->getString('mission')) ?? FleetMission::Transport;
        try {
            $order = array_map('floatval', $form->all('order'));
            $speed = Navigation::parsePercent($form->getString('speed'));
            $this->addFlash('success', $this->fleetService->sendFleet($fleet, $this->player(), $form->getInt('galaxy'), $form->getInt('system'), $form->getInt('position'), $mission, $this->cargo($request), $order, $speed));
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleets/{id}/gather', name: 'app_fleets_gather', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function gather(Fleet $fleet, Request $request): Response
    {
        $this->currentPlanet();
        $types = array_values(array_filter(array_map(
            static fn (mixed $value): ?ShipType => ShipType::tryFrom((string) $value),
            $request->request->all('types'),
        )));

        try {
            $report = $this->fleetService->gather($fleet, $this->player(), $request->request->getInt('minutes'), $types);
            $this->addFlash('success', 'Schiffe werden zusammengezogen: '.implode('; ', $report).'.');
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleets/{id}/transfer', name: 'app_fleets_transfer', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function transfer(Fleet $fleet, Request $request): Response
    {
        $this->currentPlanet();
        $intoFleet = 'in' === $request->request->getString('direction');
        $this->attempt(
            fn () => $this->fleetService->transfer($fleet, $this->player(), $this->ships($request), $intoFleet),
            $intoFleet ? 'Schiffe in die Flotte aufgenommen.' : 'Schiffe in den Hangar verlegt.',
        );

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleets/{id}/split', name: 'app_fleets_split', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function split(Fleet $fleet, Request $request): Response
    {
        $this->currentPlanet();
        $name = $request->request->getString('name');
        $this->attempt(fn () => $this->fleetService->split($fleet, $this->player(), $name, $this->ships($request)), \sprintf('Neue Flotte „%s“ abgespalten.', trim($name)));

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleets/{id}/release-alliance', name: 'app_fleets_release_alliance', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function releaseToAlliance(Fleet $fleet, Request $request): Response
    {
        $this->currentPlanet();
        $released = $request->request->getBoolean('released');
        $this->attempt(
            fn () => $this->fleetService->releaseToAlliance($fleet, $this->player(), $released),
            $released ? 'Flotte für die Allianz freigegeben.' : 'Allgemeine Freigabe zurückgenommen.',
        );

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleets/{id}/release', name: 'app_fleets_release', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function releaseTo(Fleet $fleet, Request $request, UserRepository $users): Response
    {
        $this->currentPlanet();
        $member = $users->find($request->request->getInt('member'));
        if (null === $member) {
            $this->addFlash('error', 'Bitte wähle ein Mitglied aus.');
        } else {
            $this->attempt(fn () => $this->fleetService->releaseTo($fleet, $this->player(), $member), \sprintf('Flotte für %s freigegeben.', $member->getUsername()));
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleets/{id}/revoke/{member}', name: 'app_fleets_revoke', requirements: ['id' => Requirement::DIGITS, 'member' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function revoke(Fleet $fleet, int $member, UserRepository $users): Response
    {
        $this->currentPlanet();
        $user = $users->find($member);
        if (null !== $user) {
            $this->attempt(fn () => $this->fleetService->revokeFrom($fleet, $this->player(), $user), \sprintf('Freigabe für %s zurückgenommen.', $user->getUsername()));
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleets/{id}/hover', name: 'app_fleets_hover', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function hover(Fleet $fleet, Request $request): Response
    {
        $this->currentPlanet();
        try {
            $this->addFlash('success', $this->fleetService->hover($fleet, $this->player(), $request->request->getString('source')));
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleets/{id}/unload', name: 'app_fleets_unload', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function unload(Fleet $fleet): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->fleetService->unloadCargo($fleet, $this->player()), 'Ladung abgeladen.');

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleets/{id}/dissolve', name: 'app_fleets_dissolve', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function dissolve(Fleet $fleet): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->fleetService->dissolve($fleet, $this->player()), 'Flotte aufgelöst, die Schiffe stehen im Hangar.');

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleet/{id}/recall', name: 'app_fleet_recall', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function recall(Fleet $fleet, Request $request): Response
    {
        $this->currentPlanet();
        $hovering = FleetMission::Hover === $fleet->getMission();
        $this->attempt(fn () => $this->fleetService->recall($fleet, $this->player()), \sprintf($hovering ? '%s ist wieder gelandet.' : '%s kehrt um.', $fleet->getLabel()));

        return $this->backTo($request, 'app_fleet');
    }

    /** Actions of the fleet table for the selected fleets. */
    public const array BULK_ACTIONS = [
        'recall' => 'Zurückrufen / sofort landen',
        'merge' => 'Zusammenlegen (am selben Ort)',
        'hover' => 'Schweben',
        'gather' => 'Schiffe zusammenziehen (eine Flotte)',
        'load' => 'Schiffe aus dem Hangar aufnehmen (eine Flotte)',
        'drop' => 'Schiffe in den Hangar abgeben (eine Flotte)',
        'split' => 'Aufteilen (eine Flotte)',
        'rename' => 'Umbenennen (eine Flotte)',
        'unload' => 'Ladung abladen',
        'dissolve' => 'Auflösen (Schiffe in den Hangar)',
        'release_alliance' => 'Für die Allianz freigeben',
        'unrelease_alliance' => 'Allgemeine Freigabe zurücknehmen',
        'release_member' => 'Einzeln für ein Mitglied freigeben',
        'revoke_member' => 'Einzelfreigabe zurücknehmen',
    ];

    /** Runs one action for every selected fleet; each result becomes a flash message. */
    #[Route('/fleets/bulk', name: 'app_fleets_bulk', methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function bulk(Request $request, FleetRepository $fleets, UserRepository $users): Response
    {
        $this->currentPlanet();
        $player = $this->player();
        $form = $request->request;
        $action = $form->getString('action');
        $selected = array_values(array_filter(array_map(
            static fn (mixed $id): ?Fleet => $fleets->find((int) $id),
            array_unique($form->all('fleets')),
        )));

        if ([] === $selected) {
            $this->addFlash('error', 'Wähle mindestens eine Flotte aus.');

            return $this->redirectToRoute('app_fleet');
        }
        // "send:<mission>" sends the fleets on that mission.
        $mission = str_starts_with($action, 'send:') ? FleetMission::tryFrom(substr($action, 5)) : null;
        if (null !== $mission) {
            if (!\in_array($mission, FleetMission::selectable(), true)) {
                $mission = null;
            }
            $action = null === $mission ? '' : 'send';
        }
        if ('send' !== $action && !isset(self::BULK_ACTIONS[$action])) {
            $this->addFlash('error', 'Wähle eine Aktion aus.');

            return $this->redirectToRoute('app_fleet');
        }
        $single = \in_array($action, ['gather', 'load', 'drop', 'split', 'rename'], true);
        if ($single && 1 !== \count($selected)) {
            $this->addFlash('error', \sprintf('„%s“ geht nur mit genau einer ausgewählten Flotte.', self::BULK_ACTIONS[$action]));

            return $this->redirectToRoute('app_fleet');
        }

        if ('merge' === $action) {
            $this->attempt(fn () => $this->fleetService->merge($selected, $player), \sprintf('%d Flotten zu „%s“ zusammengelegt.', \count($selected), $selected[0]->getLabel()));

            return $this->redirectToRoute('app_fleet');
        }

        $cargo = $this->cargo($request);
        if ('send' === $action && \count($selected) > 1 && !$cargo->isEmpty()) {
            $this->addFlash('error', 'Ladung kann nur eine einzelne Flotte mitnehmen. Wähle nur eine Flotte aus oder lass die Ladung leer.');

            return $this->redirectToRoute('app_fleet');
        }
        $member = \in_array($action, ['release_member', 'revoke_member'], true) ? $users->find($form->getInt('member')) : null;

        foreach ($selected as $fleet) {
            $label = $fleet->getLabel();
            $id = (string) $fleet->getId();
            try {
                $message = match ($action) {
                    'send' => $this->fleetService->sendFleet($fleet, $player, $form->getInt('galaxy'), $form->getInt('system'), $form->getInt('position'),
                        $mission, $cargo,
                        [], Navigation::parsePercent($form->getString('speed'))),
                    'recall' => $this->run(fn () => $this->fleetService->recall($fleet, $player), FleetMission::Hover === $fleet->getMission() ? 'ist wieder gelandet' : 'kehrt um', $label),
                    'hover' => $this->fleetService->hover($fleet, $player, $form->getString('source')),
                    'gather' => 'Schiffe werden zusammengezogen: '.implode('; ', $this->fleetService->gather($fleet, $player, $form->getInt('minutes'), array_values(array_filter(array_map(
                        static fn (mixed $v): ?ShipType => ShipType::tryFrom((string) $v), $form->all('types')))))).'.',
                    'load' => $this->run(fn () => $this->fleetService->transfer($fleet, $player, array_map('intval', $form->all('load')[$id] ?? []), true), 'hat Schiffe aus dem Hangar aufgenommen', $label),
                    'drop' => $this->run(fn () => $this->fleetService->transfer($fleet, $player, array_map('intval', $form->all('drop')[$id] ?? []), false), 'hat Schiffe in den Hangar abgegeben', $label),
                    'split' => $this->run(fn () => $this->fleetService->split($fleet, $player, $form->getString('name'), array_map('intval', $form->all('split')[$id] ?? [])), \sprintf('wurde aufgeteilt, neue Flotte „%s“', trim($form->getString('name'))), $label),
                    'rename' => $this->run(fn () => $this->fleetService->rename($fleet, $player, $form->getString('name')), \sprintf('heißt jetzt „%s“', trim($form->getString('name'))), $label),
                    'unload' => $this->run(fn () => $this->fleetService->unloadCargo($fleet, $player), 'hat die Ladung abgeladen', $label),
                    'dissolve' => $this->run(fn () => $this->fleetService->dissolve($fleet, $player), 'wurde aufgelöst, die Schiffe stehen im Hangar', $label),
                    'release_alliance' => $this->run(fn () => $this->fleetService->releaseToAlliance($fleet, $player, true), 'ist für die Allianz freigegeben', $label),
                    'unrelease_alliance' => $this->run(fn () => $this->fleetService->releaseToAlliance($fleet, $player, false), 'ist nicht mehr allgemein freigegeben', $label),
                    'release_member' => null === $member ? throw new GameException('Bitte wähle ein Mitglied aus.')
                        : $this->run(fn () => $this->fleetService->releaseTo($fleet, $player, $member), 'ist für '.$member->getUsername().' freigegeben', $label),
                    'revoke_member' => null === $member ? throw new GameException('Bitte wähle ein Mitglied aus.')
                        : $this->run(fn () => $this->fleetService->revokeFrom($fleet, $player, $member), 'ist für '.$member->getUsername().' nicht mehr freigegeben', $label),
                };
                $this->addFlash('success', $message);
            } catch (GameException $e) {
                $this->addFlash('error', \count($selected) > 1 ? $label.': '.$e->getMessage() : $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_fleet');
    }

    /** Runs a service call and returns "<fleet> <what happened>." for the flash message. */
    private function run(callable $operation, string $outcome, string $label): string
    {
        $operation();

        return \sprintf('%s %s.', $label, $outcome);
    }

    /** @return array<string, int> */
    private function ships(Request $request): array
    {
        return array_map('intval', $request->request->all('ships'));
    }

    private function cargo(Request $request): Resources
    {
        $cargo = $request->request->all('cargo');

        return Resources::fromCallback(static fn (ResourceType $type): float => max(0, (int) ($cargo[$type->value] ?? 0)));
    }
}
