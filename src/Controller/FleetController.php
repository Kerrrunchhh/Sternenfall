<?php

namespace App\Controller;

use App\Controller\Attribute\RequiresFleetControl;
use App\Entity\Fleet;
use App\Game\FleetMission;
use App\Game\FleetService;
use App\Game\Navigation;
use App\Game\Radar;
use App\Game\GameException;
use App\Game\Resources;
use App\Game\ResourceType;
use App\Game\ShipType;
use App\Entity\Squadron;
use App\Game\FleetFilters;
use App\Game\SquadronService;
use App\Repository\BookmarkRepository;
use App\Repository\SquadronRepository;
use App\Repository\FleetRepository;
use App\Repository\PlanetRepository;
use App\Repository\UserRepository;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use App\I18n\Lines;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

class FleetController extends AbstractGameController
{
    #[Route('/fleet', name: 'app_fleet', methods: ['GET'])]
    public function index(Request $request, FleetRepository $fleets, PlanetRepository $planets, Radar $radar, Navigation $navigation, BookmarkRepository $bookmarks, SquadronRepository $squadrons): Response
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
                'fuel' => $navigation->fuelBase($fleet->getShips()),
                'crew' => $navigation->crew($fleet->getShips()),
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
                'fuel' => $navigation->fuelBase($fleet->getShips()),
                'crew' => $navigation->crew($fleet->getShips()),
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
            'squadrons' => $squadrons->findByOwner($player),
            'game_speed' => $this->economy->speed(),
            'fleet_control' => $planet->getBuildingLevel(\App\Game\BuildingType::FleetControl) > 0,
            'fleet_capacity' => \App\Game\Economy::fleetCapacity($player),
            'named_fleets' => $fleets->countNamed($player),
            'hangar' => $hangar,
            // Own fleets waiting at the current planet can take ships from its hangar.
            'here_fleets' => array_values(array_map(
                static fn (array $e): Fleet => $e['fleet'],
                array_filter($entries, static fn (array $e): bool => $e['fleet']->isIdle() && $e['base']?->getId() === $planet->getId()),
            )),
            'entries' => $entries,
            'shared' => $shared,
            'allies' => $allies,
            'missions' => FleetMission::selectable(),
            // Attacks on their way that own fleets may join (joint attack).
            'joinable_attacks' => $this->fleetService->joinableAttacks($player),
            'ship_types' => ShipType::cases(),
            // Foreign fleets heading here: attacks always, everything else only if the radar sees it.
            'incoming' => array_values(array_filter($fleets->findIncoming($player), static fn (Fleet $f): bool => $radar->knows($player, $f))),
            'target' => [
                'galaxy' => $request->query->getInt('galaxy', $planet->getGalaxy()),
                'system' => $request->query->getInt('system', $planet->getSystem()),
                'position' => $request->query->getInt('position', $planet->getPosition()),
                // Preselected in the action dropdown when coming from the universe view.
                'mission' => FleetMission::tryFrom($request->query->getString('mission')),
            ],
        ]);
    }

    #[RequiresFleetControl]
    #[Route('/fleets/create', name: 'app_fleets_create', methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function create(Request $request): Response
    {
        $planet = $this->currentPlanet();
        $name = $request->request->getString('name');
        $this->attempt(fn () => $this->fleetService->create($planet, $name, $this->ships($request)), t('Flotte „{value}“ gebildet.', ['{value}' => trim($name)]));

        return $this->redirectToRoute('app_fleet');
    }

    /** Hangar section: chosen ships of the current planet join a fleet waiting there. */
    #[RequiresFleetControl]
    #[Route('/fleets/add-from-hangar', name: 'app_fleets_add_from_hangar', methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function addFromHangar(Request $request, FleetRepository $fleets): Response
    {
        $planet = $this->currentPlanet();
        $id = $request->request->getString('fleet');
        $fleet = ctype_digit($id) ? $fleets->find((int) $id) : null;
        if (null === $fleet || !$fleet->getOwner()->isSameAs($this->player())) {
            $this->addFlash('error', t('Wähle eine deiner Flotten aus.'));

            return $this->redirectToRoute('app_fleet');
        }
        $this->attempt(function () use ($fleet, $planet, $request): void {
            if (!$fleet->isIdle() || $fleet->getGalaxy() !== $planet->getGalaxy() || $fleet->getSystem() !== $planet->getSystem() || !$planet->covers($fleet->getPosition())) {
                throw new GameException(t('{label} wartet nicht an {name}.', ['{label}' => $fleet->getLabel(), '{name}' => $planet->getName()]));
            }
            $this->fleetService->transfer($fleet, $this->player(), $this->ships($request), true);
        }, t('Schiffe aus dem Hangar von {name} zu {label} hinzugefügt.', ['{name}' => $planet->getName(), '{label}' => $fleet->getLabel()]));

        return $this->redirectToRoute('app_fleet');
    }

    #[RequiresFleetControl]
    #[Route('/fleets/{id}/rename', name: 'app_fleets_rename', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function rename(Fleet $fleet, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->fleetService->rename($fleet, $this->player(), $request->request->getString('name')), t('Flotte umbenannt.'));

        return $this->redirectToRoute('app_fleet');
    }

    #[RequiresFleetControl]
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
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[RequiresFleetControl]
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
            $this->addFlash('success', t('Schiffe werden zusammengezogen: {report}.', ['{report}' => new Lines($report, '; ')]));
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[RequiresFleetControl]
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

    #[RequiresFleetControl]
    #[Route('/fleets/{id}/split', name: 'app_fleets_split', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function split(Fleet $fleet, Request $request): Response
    {
        $this->currentPlanet();
        $name = $request->request->getString('name');
        $this->attempt(fn () => $this->fleetService->split($fleet, $this->player(), $name, $this->ships($request)), t('Neue Flotte „{value}“ abgespalten.', ['{value}' => trim($name)]));

        return $this->redirectToRoute('app_fleet');
    }

    #[RequiresFleetControl]
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

    #[RequiresFleetControl]
    #[Route('/fleets/{id}/release', name: 'app_fleets_release', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function releaseTo(Fleet $fleet, Request $request, UserRepository $users): Response
    {
        $this->currentPlanet();
        $member = $users->find($request->request->getInt('member'));
        if (null === $member) {
            $this->addFlash('error', t('Bitte wähle ein Mitglied aus.'));
        } else {
            $this->attempt(fn () => $this->fleetService->releaseTo($fleet, $this->player(), $member), t('Flotte für {player} freigegeben.', ['{player}' => $member->getUsername()]));
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[RequiresFleetControl]
    #[Route('/fleets/{id}/revoke/{member}', name: 'app_fleets_revoke', requirements: ['id' => Requirement::DIGITS, 'member' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function revoke(Fleet $fleet, int $member, UserRepository $users): Response
    {
        $this->currentPlanet();
        $user = $users->find($member);
        if (null !== $user) {
            $this->attempt(fn () => $this->fleetService->revokeFrom($fleet, $this->player(), $user), t('Freigabe für {player} zurückgenommen.', ['{player}' => $user->getUsername()]));
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[RequiresFleetControl]
    #[Route('/fleets/{id}/hover', name: 'app_fleets_hover', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function hover(Fleet $fleet, Request $request): Response
    {
        $this->currentPlanet();
        try {
            $this->addFlash('success', $this->fleetService->hover($fleet, $this->player(), $request->request->getString('source')));
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[RequiresFleetControl]
    #[Route('/fleets/{id}/unload', name: 'app_fleets_unload', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function unload(Fleet $fleet): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->fleetService->unloadCargo($fleet, $this->player()), t('Ladung abgeladen.'));

        return $this->redirectToRoute('app_fleet');
    }

    #[RequiresFleetControl]
    #[Route('/fleets/{id}/dissolve', name: 'app_fleets_dissolve', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function dissolve(Fleet $fleet): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->fleetService->dissolve($fleet, $this->player()), t('Flotte aufgelöst, die Schiffe stehen im Hangar.'));

        return $this->redirectToRoute('app_fleet');
    }

    #[RequiresFleetControl]
    #[Route('/fleet/{id}/recall', name: 'app_fleet_recall', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function recall(Fleet $fleet, Request $request): Response
    {
        $this->currentPlanet();
        $hovering = FleetMission::Hover === $fleet->getMission();
        $this->attempt(fn () => $this->fleetService->recall($fleet, $this->player()), $hovering ? t('{label} ist wieder gelandet.', ['{label}' => $fleet->getLabel()]) : t('{label} kehrt um.', ['{label}' => $fleet->getLabel()]));

        return $this->backTo($request, 'app_fleet');
    }

    /** Actions of the fleet table for the selected fleets. */
    public const array BULK_ACTIONS = [
        'recall' => 'Zurückrufen / sofort landen',
        'merge' => 'Zusammenlegen (am selben Ort)',
        'gather' => 'Schiffe zusammenziehen (eine Flotte)',
        'load' => 'Schiffe aus dem Hangar aufnehmen (eine Flotte)',
        'drop' => 'Schiffe aus der Flotte entfernen (eine Flotte)',
        'split' => 'Aufteilen (eine Flotte)',
        'rename' => 'Umbenennen (eine Flotte)',
        'unload' => 'Ladung abladen',
        'dissolve' => 'Auflösen (Schiffe in den Hangar)',
        'release_alliance' => 'Für die Allianz freigeben',
        'unrelease_alliance' => 'Allgemeine Freigabe zurücknehmen',
        'release_member' => 'Einzeln für ein Mitglied freigeben',
        'revoke_member' => 'Einzelfreigabe zurücknehmen',
        'squadron_add' => 'Zu einem Geschwader hinzufügen',
        'squadron_remove' => 'Aus einem Geschwader entfernen',
    ];

    /** Runs one action for every selected fleet; each result becomes a flash message. */
    #[RequiresFleetControl]
    #[Route('/fleets/bulk', name: 'app_fleets_bulk', methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function bulk(Request $request, FleetRepository $fleets, UserRepository $users, SquadronRepository $squadrons, SquadronService $squadronService): Response
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
            $this->addFlash('error', t('Wähle mindestens eine Flotte aus.'));

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
        if (('send' === $action && null === $mission) || ('send' !== $action && !isset(self::BULK_ACTIONS[$action]))) {
            $this->addFlash('error', t('Wähle eine Aktion aus.'));

            return $this->redirectToRoute('app_fleet');
        }
        $single = \in_array($action, ['gather', 'load', 'drop', 'split', 'rename'], true);
        if ($single && 1 !== \count($selected)) {
            $this->addFlash('error', t('„{value}“ geht nur mit genau einer ausgewählten Flotte.', ['{value}' => self::BULK_ACTIONS[$action]]));

            return $this->redirectToRoute('app_fleet');
        }

        if (\in_array($action, ['squadron_add', 'squadron_remove'], true)) {
            // "– neues Geschwader –" sends an empty value.
            $squadronId = $form->getString('squadron');
            $squadron = ctype_digit($squadronId) ? $squadrons->find((int) $squadronId) : null;
            if ('squadron_add' === $action) {
                $this->attempt(
                    fn () => $squadronService->add($player, $squadron, $form->getString('squadron_name'), $selected),
                    t('{count} Flotte(n) im Geschwader „{value}“.', ['{count}' => \count($selected), '{value}' => $squadron?->getName() ?? trim($form->getString('squadron_name'))]),
                );
            } elseif (null === $squadron) {
                $this->addFlash('error', t('Wähle ein Geschwader aus.'));
            } else {
                $this->attempt(fn () => $squadronService->remove($player, $squadron, $selected), t('{count} Flotte(n) aus „{name}“ entfernt.', ['{count}' => \count($selected), '{name}' => $squadron->getName()]));
            }

            return $this->redirectToRoute('app_fleet');
        }

        if ('merge' === $action) {
            $this->attempt(fn () => $this->fleetService->merge($selected, $player), t('{count} Flotten zu „{label}“ zusammengelegt.', ['{count}' => \count($selected), '{label}' => $selected[0]->getLabel()]));

            return $this->redirectToRoute('app_fleet');
        }

        $cargo = $this->cargo($request);
        if ('send' === $action && \count($selected) > 1 && !$cargo->isEmpty() && !\in_array($mission, FleetService::GROUP_MISSIONS, true)) {
            $this->addFlash('error', t('Ladung kann nur eine einzelne Flotte mitnehmen. Wähle nur eine Flotte aus oder lass die Ladung leer.'));

            return $this->redirectToRoute('app_fleet');
        }
        $member = \in_array($action, ['release_member', 'revoke_member'], true) ? $users->find($form->getInt('member')) : null;

        // Several fleets attacking or transporting together (joint attack, convoy with escort) arrive together.
        $lead = FleetMission::Attack === $mission ? $this->leadFleet($form->getString('join'), $fleets) : null;
        if ('send' === $action && \in_array($mission, FleetService::GROUP_MISSIONS, true) && (\count($selected) > 1 || null !== $lead)) {
            try {
                foreach ($this->fleetService->sendGroup($selected, $player, $form->getInt('galaxy'), $form->getInt('system'), $form->getInt('position'), $mission, $this->cargo($request), Navigation::parsePercent($form->getString('speed')), $lead) as $line) {
                    $this->addFlash($line['ok'] ? 'success' : 'error', $line['text']);
                }
            } catch (GameException $e) {
                $this->addFlash('error', $e->translatable());
            }

            return $this->redirectToRoute('app_fleet');
        }

        foreach ($selected as $fleet) {
            $label = $fleet->getLabel();
            $id = (string) $fleet->getId();
            try {
                $message = match ($action) {
                    'send' => $this->fleetService->sendFleet($fleet, $player, $form->getInt('galaxy'), $form->getInt('system'), $form->getInt('position'),
                        $mission, $cargo,
                        FleetMission::Pickup === $mission ? $this->pickupOrder($request) : [], Navigation::parsePercent($form->getString('speed'))),
                    'recall' => $this->run(fn () => $this->fleetService->recall($fleet, $player), FleetMission::Hover === $fleet->getMission() ? t('{label} ist wieder gelandet.', ['{label}' => $label]) : t('{label} kehrt um.', ['{label}' => $label])),
                    'gather' => t('Schiffe werden zusammengezogen: {report}.', ['{report}' => new Lines($this->fleetService->gather($fleet, $player, $form->getInt('minutes'), array_values(array_filter(array_map(
                        static fn (mixed $v): ?ShipType => ShipType::tryFrom((string) $v), $form->all('types'))))), '; ')]),
                    'load' => $this->run(fn () => $this->fleetService->transfer($fleet, $player, array_map('intval', $form->all('load')[$id] ?? []), true), t('{label} hat Schiffe aus dem Hangar aufgenommen.', ['{label}' => $label])),
                    'drop' => t('{label} {what}.', ['{label}' => $label, '{what}' => $this->fleetService->removeShips($fleet, $player, array_map('intval', $form->all('drop')[$id] ?? []))]),
                    'split' => $this->run(fn () => $this->fleetService->split($fleet, $player, $form->getString('name'), array_map('intval', $form->all('split')[$id] ?? [])), t('{label} wurde aufgeteilt, neue Flotte „{value}“.', ['{label}' => $label, '{value}' => trim($form->getString('name'))])),
                    'rename' => $this->run(fn () => $this->fleetService->rename($fleet, $player, $form->getString('name')), t('{label} heißt jetzt „{value}“.', ['{label}' => $label, '{value}' => trim($form->getString('name'))])),
                    'unload' => $this->run(fn () => $this->fleetService->unloadCargo($fleet, $player), t('{label} hat die Ladung abgeladen.', ['{label}' => $label])),
                    'dissolve' => $this->run(fn () => $this->fleetService->dissolve($fleet, $player), t('{label} wurde aufgelöst, die Schiffe stehen im Hangar.', ['{label}' => $label])),
                    'release_alliance' => $this->run(fn () => $this->fleetService->releaseToAlliance($fleet, $player, true), t('{label} ist für die Allianz freigegeben.', ['{label}' => $label])),
                    'unrelease_alliance' => $this->run(fn () => $this->fleetService->releaseToAlliance($fleet, $player, false), t('{label} ist nicht mehr allgemein freigegeben.', ['{label}' => $label])),
                    'release_member' => null === $member ? throw new GameException(t('Bitte wähle ein Mitglied aus.'))
                        : $this->run(fn () => $this->fleetService->releaseTo($fleet, $player, $member), t('{label} ist für {player} freigegeben.', ['{label}' => $label, '{player}' => $member->getUsername()])),
                    'revoke_member' => null === $member ? throw new GameException(t('Bitte wähle ein Mitglied aus.'))
                        : $this->run(fn () => $this->fleetService->revokeFrom($fleet, $player, $member), t('{label} ist für {player} nicht mehr freigegeben.', ['{label}' => $label, '{player}' => $member->getUsername()])),
                };
                $this->addFlash('success', $message);
            } catch (GameException $e) {
                $this->addFlash('error', \count($selected) > 1 ? t('{label}: {error}', ['{label}' => $label, '{error}' => $e->translatable()]) : $e->translatable());
            }
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleet-filters', name: 'app_fleet_filter_save', methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function saveFilter(Request $request, FleetFilters $filters): Response
    {
        $this->currentPlanet();
        $form = $request->request;
        $this->attempt(
            fn () => $filters->save($this->player(), $form->getString('name'), $form->getString('color'), $form->getString('definition')),
            t('Filter „{value}“ gespeichert.', ['{value}' => trim($form->getString('name'))]),
        );

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/fleet-filters/delete', name: 'app_fleet_filter_delete', methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function deleteFilter(Request $request, FleetFilters $filters): Response
    {
        $this->currentPlanet();
        $name = $request->request->getString('name');
        $this->attempt(fn () => $filters->delete($this->player(), $name), t('Filter „{name}“ gelöscht.', ['{name}' => $name]));

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/squadrons/{id}/delete', name: 'app_squadron_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fleet', tokenKey: '_token')]
    public function deleteSquadron(Squadron $squadron, SquadronService $squadronService): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $squadronService->delete($this->player(), $squadron), t('Geschwader „{name}“ aufgelöst – die Flotten bleiben, wie sie sind.', ['{name}' => $squadron->getName()]));

        return $this->redirectToRoute('app_fleet');
    }

    /** Runs a service call and returns "<fleet> <what happened>." for the flash message. */
    private function run(callable $operation, TranslatableInterface $outcome): TranslatableInterface
    {
        $operation();

        return $outcome;
    }

    /** @return array<string, int> */
    private function ships(Request $request): array
    {
        return array_map('intval', $request->request->all('ships'));
    }

    /** The attack a fleet joins (joint attack), if one was chosen. */
    private function leadFleet(string $id, FleetRepository $fleets): ?Fleet
    {
        return ctype_digit($id) ? $fleets->find((int) $id) : null;
    }

    /** @return array<string, float> resource => amount each fleet picks up */
    private function pickupOrder(Request $request): array
    {
        $wanted = $request->request->all('pickup');
        $order = [];
        foreach (ResourceType::cases() as $type) {
            $amount = max(0, (int) ($wanted[$type->value] ?? 0));
            if ($amount > 0) {
                $order[$type->value] = (float) $amount;
            }
        }

        return $order;
    }

    private function cargo(Request $request): Resources
    {
        $cargo = $request->request->all('cargo');

        return Resources::fromCallback(static fn (ResourceType $type): float => max(0, (int) ($cargo[$type->value] ?? 0)));
    }
}
