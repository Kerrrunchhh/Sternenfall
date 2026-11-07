<?php

namespace App\Controller;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\Economy;
use App\Game\FleetService;
use App\Game\GameException;
use App\Game\PlanetService;
use App\Game\ShipType;
use App\Repository\AllianceRepository;
use App\Repository\MessageRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Shared plumbing for all in-game pages: the current planet and the data of the top bar.
 */
#[IsGranted('ROLE_USER')]
abstract class AbstractGameController extends AbstractController
{
    protected const string SESSION_PLANET = 'current_planet_id';

    /** Pages a form may send the player back to (no open redirects). */
    protected const array GAME_ROUTES = [
        'app_overview', 'app_buildings', 'app_queues', 'app_research', 'app_shipyard', 'app_fleet', 'app_galaxy',
        'app_radar', 'app_fusion', 'app_trade', 'app_messages', 'app_lifeform', 'app_alliance',
    ];

    protected PlanetService $planetService;
    protected FleetService $fleetService;
    protected Economy $economy;
    protected ClockInterface $clock;
    private MessageRepository $messages;
    private RequestStack $requestStack;
    private AllianceRepository $allianceRepository;

    #[Required]
    public function setGameServices(
        PlanetService $planetService,
        FleetService $fleetService,
        Economy $economy,
        ClockInterface $clock,
        MessageRepository $messages,
        RequestStack $requestStack,
        AllianceRepository $allianceRepository,
    ): void {
        $this->allianceRepository = $allianceRepository;
        $this->planetService = $planetService;
        $this->fleetService = $fleetService;
        $this->economy = $economy;
        $this->clock = $clock;
        $this->messages = $messages;
        $this->requestStack = $requestStack;
    }

    /** Redirects to the game page named in the "to" parameter, or to the fallback. */
    protected function backTo(\Symfony\Component\HttpFoundation\Request $request, string $fallback): Response
    {
        $route = $request->request->getString('to', $request->query->getString('to'));

        return $this->redirectToRoute(\in_array($route, self::GAME_ROUTES, true) ? $route : $fallback);
    }

    protected function player(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    /** @param array<string, mixed> $parameters */
    protected function renderGame(string $view, Planet $planet, array $parameters = []): Response
    {
        return $this->render($view, [...$this->viewData($planet), ...$parameters]);
    }

    /**
     * Runs a game action and turns rule violations into flash messages.
     *
     * @param callable(): void $action
     */
    protected function attempt(callable $action, string $successMessage): void
    {
        try {
            $action();
            $this->addFlash('success', $successMessage);
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());
        }
    }

    /** The planet the player is looking at, brought up to date. */
    protected function currentPlanet(): Planet
    {
        // Fleets may have arrived anywhere in the meantime (including colonies of this player).
        $this->fleetService->processDue();

        $user = $this->player();
        $planets = $user->getPlanets();
        $selectedId = $this->requestStack->getSession()->get(self::SESSION_PLANET);
        $planet = $planets->findFirst(static fn (int $key, Planet $p): bool => $p->getId() === $selectedId) ?? $planets->first();
        if (!$planet instanceof Planet) {
            throw $this->createNotFoundException('Du besitzt keinen Planeten.');
        }

        $this->planetService->synchronize($planet);

        return $planet;
    }

    /** @return array<string, mixed> */
    protected function viewData(Planet $planet): array
    {
        $production = $this->economy->productionPerHour($planet);
        $consumption = $this->economy->consumptionPerHour($planet->getPopulation());
        $capacity = $this->economy->populationCapacity($planet);

        return [
            'planet' => $planet,
            'production' => $production,
            'consumption' => $consumption,
            'net' => $production->subtract($consumption),
            'protected' => $this->economy->protectedAmount($planet),
            'population_capacity' => $capacity,
            'population_growth' => $planet->getPopulation() < $capacity ? $this->economy->populationGrowthPerHour($planet) : 0.0,
            'bunker_capacity' => $this->economy->bunkerCapacity($planet),
            'player' => $planet->getOwner(),
            'unread_messages' => $this->messages->countUnread($planet->getOwner()),
            // The alliance black board is shown on every page.
            'board_posts' => true === $planet->getOwner()->getAllianceRank()?->canSeeBoard()
                ? $this->allianceRepository->findPosts($planet->getOwner()->getAlliance(), 20)
                : [],
            'credits_per_hour' => $this->economy->creditsPerHour(array_sum(array_map(
                static fn (Planet $p): float => $p->getPopulation(),
                $planet->getOwner()->getPlanets()->toArray(),
            ))),
            'hangar' => array_values(array_filter(
                array_map(static fn (ShipType $type): array => ['type' => $type, 'count' => $planet->getShipCount($type)], ShipType::cases()),
                static fn (array $entry): bool => $entry['count'] > 0,
            )),
            'now' => $this->clock->now(),
        ];
    }
}
