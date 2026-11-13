<?php

namespace App\Controller;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\Diplomacy;
use App\Game\Economy;
use App\Game\Radar;
use App\Game\FleetService;
use App\Game\GameException;
use App\Game\PlanetService;
use App\Game\ShipType;
use App\Repository\AllianceRepository;
use App\Repository\ContactRepository;
use App\Repository\PlayerMessageRepository;
use App\Repository\TickerRepository;
use App\Repository\TicketRepository;
use App\Repository\TradePostRepository;
use App\Repository\MessageRepository;
use App\Repository\NewsRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Shared plumbing for all in-game pages: the current planet and the data of the top bar.
 */
#[IsGranted('ROLE_USER')]
abstract class AbstractGameController extends AbstractController
{
    public const string SESSION_PLANET = 'current_planet_id';

    /** Pages a form may send the player back to (no open redirects). */
    protected const array GAME_ROUTES = [
        'app_overview', 'app_buildings', 'app_queues', 'app_research', 'app_shipyard', 'app_defense', 'app_fleet', 'app_galaxy', 'app_trade_fusionators', 'app_bookmarks',
        'app_radar', 'app_fusion', 'app_trade', 'app_messages', 'app_lifeform', 'app_alliance', 'app_mail', 'app_newspaper', 'app_tickers', 'app_highscores', 'app_diplomacy', 'app_notes', 'app_account', 'app_support', 'app_news', 'app_empire', 'app_guide',
    ];

    protected PlanetService $planetService;
    protected FleetService $fleetService;
    protected Economy $economy;
    protected ClockInterface $clock;
    private MessageRepository $messages;
    private PlayerMessageRepository $letters;
    private ContactRepository $contacts;
    private TickerRepository $tickers;
    private Diplomacy $diplomacy;
    private Radar $radar;
    private TicketRepository $tickets2;
    private string $forumUrl;
    private RequestStack $requestStack;
    private AllianceRepository $allianceRepository;
    private TradePostRepository $tradePosts;
    private NewsRepository $newsRepository;

    private \App\Game\AttackBans $attackBans;

    private \App\Repository\FleetRepository $fleetRepository;

    private \App\Game\Colonizer $colonizer;

    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    #[Required]
    public function setColonizer(\App\Game\Colonizer $colonizer, \Doctrine\ORM\EntityManagerInterface $entityManager): void
    {
        $this->colonizer = $colonizer;
        $this->entityManager = $entityManager;
    }

    #[Required]
    public function setNewsRepository(NewsRepository $newsRepository, \App\Game\AttackBans $attackBans, \App\Repository\FleetRepository $fleetRepository): void
    {
        $this->newsRepository = $newsRepository;
        $this->attackBans = $attackBans;
        $this->fleetRepository = $fleetRepository;
    }

    /**
     * Planet slots: allowed by the colonization research, taken by the planets (welded ones count
     * one by one, freshly invaded ones more) and by colony ships on their way.
     *
     * @return array{total: int, used: int, underway: int, free: int}
     */
    protected function planetSlots(User $player): array
    {
        $total = $this->economy->colonySlots($player);
        $used = Economy::ownedSlots($player, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $underway = $this->fleetRepository->countColonyShipsUnderway($player);

        return ['total' => $total, 'used' => $used, 'underway' => $underway, 'free' => max(0, $total - $used - $underway)];
    }

    #[Required]
    public function setGameServices(
        PlanetService $planetService,
        FleetService $fleetService,
        Economy $economy,
        ClockInterface $clock,
        MessageRepository $messages,
        RequestStack $requestStack,
        AllianceRepository $allianceRepository,
        TradePostRepository $tradePosts,
        PlayerMessageRepository $letters,
        ContactRepository $contacts,
        TickerRepository $tickers,
        Diplomacy $diplomacy,
        Radar $radar,
        TicketRepository $ticketRepository,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'FORUM_URL')]
        string $forumUrl = '',
    ): void {
        $this->tickets2 = $ticketRepository;
        $this->forumUrl = $forumUrl;
        $this->radar = $radar;
        $this->diplomacy = $diplomacy;
        $this->tickers = $tickers;
        $this->letters = $letters;
        $this->contacts = $contacts;
        $this->allianceRepository = $allianceRepository;
        $this->tradePosts = $tradePosts;
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
    protected function renderGame(string $view, Planet $planet, array $parameters = [], ?Response $response = null): Response
    {
        return $this->render($view, [...$this->viewData($planet), ...$parameters], $response);
    }

    /**
     * Runs a game action and turns rule violations into flash messages.
     *
     * @param callable(): void $action
     */
    protected function attempt(callable $action, string|TranslatableInterface $successMessage): void
    {
        try {
            $action();
            $this->addFlash('success', $successMessage);
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
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
            // All planets lost (e.g. while inactive): a fresh start on a new home world.
            $planet = $this->colonizer->createHomePlanet($user);
            $this->entityManager->flush();
            $this->addFlash('success', t('Du hattest keinen Planeten mehr – auf einer neuen Heimatwelt fängst du von vorn an.'));
        }

        $this->planetService->synchronize($planet);

        return $planet;
    }

    /** @return array<int, true> ids of own planets an attack is heading for */
    private function attackedPlanets(User $player): array
    {
        $ids = [];
        foreach ($this->radar->incomingAttacks($player) as $fleet) {
            foreach ($player->getPlanets() as $planet) {
                if ($planet->getGalaxy() === $fleet->getTargetGalaxy() && $planet->getSystem() === $fleet->getTargetSystem() && $planet->covers((int) $fleet->getTargetPosition())) {
                    $ids[(int) $planet->getId()] = true;
                }
            }
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    protected function viewData(Planet $planet): array
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        // Shown rates include the starter bonus while it lasts.
        $bonus = $this->economy->starterFactor($planet->getOwner(), $now);
        $production = $this->economy->productionPerHour($planet)->multiply($bonus);
        $consumption = $this->economy->consumptionPerHour($planet->getPopulation());
        $capacity = $this->economy->populationCapacity($planet);

        return [
            'planet' => $planet,
            'production' => $production,
            'consumption' => $consumption,
            'net' => $production->subtract($consumption),
            'protected' => $this->economy->protectedAmount($planet),
            'population_capacity' => $capacity,
            'population_growth' => $planet->getPopulation() < $capacity ? $this->economy->populationGrowthPerHour($planet) * $bonus : 0.0,
            'bunker_capacity' => $this->economy->bunkerCapacity($planet),
            'player' => $planet->getOwner(),
            'unread_messages' => $this->messages->countUnread($planet->getOwner()),
            'unread_mail' => $this->letters->countUnread($planet->getOwner()),
            'contact_requests' => $this->contacts->countIncomingRequests($planet->getOwner()),
            'ticker_invitations' => $this->tickers->countInvitations($planet->getOwner()),
            'diplomacy_votes' => $this->diplomacy->pendingVotes($planet->getOwner()),
            // Own planets with an attack on its way (seen by the radar): red in the planet list.
            'attacked_planets' => $this->attackedPlanets($planet->getOwner()),
            'open_tickets' => $this->isGranted('ROLE_MODERATOR') ? $this->tickets2->countOpen() : 0,
            'forum_url' => $this->forumUrl,
            // Own trade posts are listed under the planets in the left column.
            'own_trade_posts' => $this->tradePosts->findBy(['owner' => $planet->getOwner()], ['galaxy' => 'ASC', 'system' => 'ASC', 'position' => 'ASC']),
            // The alliance black board is shown on every page.
            'board_posts' => true === $planet->getOwner()->getAllianceRank()?->canSeeBoard()
                ? $this->allianceRepository->findPosts($planet->getOwner()->getAlliance(), 20)
                : [],
            'credits_per_hour' => $bonus * $this->economy->creditsPerHour(array_sum(array_map(
                static fn (Planet $p): float => $p->getPopulation(),
                $planet->getOwner()->getPlanets()->toArray(),
            ))),
            'starter_bonus_until' => $this->economy->hasStarterBonus($planet->getOwner(), $now) ? $this->economy->starterBonusUntil($planet->getOwner()) : null,
            'hangar' => array_values(array_filter(
                array_map(static fn (ShipType $type): array => ['type' => $type, 'count' => $planet->getShipCount($type)], ShipType::cases()),
                static fn (array $entry): bool => $entry['count'] > 0,
            )),
            // News the player has not marked read yet: shown on the overview, counted in the menu.
            'unread_news' => $this->newsRepository->findUnreadFor($planet->getOwner()),
            // A general attack ban by the admins is shown on every page.
            'attack_ban' => $this->attackBans->current(),
            'planet_slots' => $this->planetSlots($planet->getOwner()),
            'now' => $this->clock->now(),
        ];
    }
}
