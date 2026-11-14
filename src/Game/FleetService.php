<?php

namespace App\Game;

use App\I18n\When;
use App\I18n\Goods;
use App\I18n\Lines;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\User;
use App\Repository\FleetRepository;
use App\Repository\PlanetRepository;
use App\Repository\TradePostRepository;
use App\Entity\TradePost;
use App\Twig\GameExtension;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Component\Lock\LockFactory;
use App\I18n\Num;

use function Symfony\Component\Translation\t;

/**
 * Fleets: ships only move as part of a named fleet (create, load, dissolve,
 * send, gather ships). Gathering flights are short-lived unnamed groups that
 * join their fleet on arrival. Also resolves arrivals and returns.
 *
 * Fleet events are processed lazily: every game request first resolves all
 * events that are due, in chronological order, under one global lock. Every
 * operation that touches fleets takes that lock first and the player lock
 * second (via PlanetService::transaction), so they can't deadlock.
 */
final readonly class FleetService
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlanetService $planets,
        private PlanetRepository $planetRepository,
        private FleetRepository $fleetRepository,
        private Navigation $navigation,
        private Economy $economy,
        private Colonizer $colonizer,
        private FusionService $fusion,
        private TradePostRepository $tradePosts,
        private TradeService $trade,
        private CombatService $combat,
        private Radar $radar,
        private Espionage $espionage,
        private Inbox $inbox,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        private ?Diplomacy $diplomacy = null,
        private ?AttackRules $attackRules = null,
        private ?AccountDeletion $accountDeletion = null,
        private ?GameMailer $gameMailer = null,
        private ?ContractService $contracts = null,
        private ?TransportRequests $transportRequests = null,
        private ?PushingGuard $pushing = null,
        private ?BountyService $bounties = null,
        private ?GameStats $stats = null,
        private ?AttackBans $attackBans = null,
        private ?TradeGuild $tradeGuild = null,
    ) {
    }

    /**
     * Attacks on their way that the player leads: their leading fleet is his own or
     * released to him. Only he can add further fleets – the others release their fleets.
     *
     * @return list<Fleet>
     */
    public function joinableAttacks(User $player): array
    {
        return array_values(array_filter(
            $this->fleetRepository->findLeadingAttacks($this->now()),
            static fn (Fleet $lead): bool => $lead->isControllableBy($player),
        ));
    }

    /** Missions several fleets can fly together, arriving at the same time. */
    public const array GROUP_MISSIONS = [FleetMission::Attack, FleetMission::Transport, FleetMission::TransportStation];

    /**
     * Fleets flying together: a joint attack, or a convoy of trade fleets with warships as
     * escort. All fleets the actor controls (own and released to him) arrive together – at
     * the time of the slowest one, or with the running attack/convoy they are added to.
     * The cargo is shared among the fleets with cargo space.
     *
     * @param list<Fleet> $fleets
     *
     * @return list<array{ok: bool, text: TranslatableInterface}> one result per fleet
     */
    public function sendGroup(array $fleets, User $actor, int $galaxy, int $system, int $position, FleetMission $mission, Resources $cargo, float $speedPercent, ?Fleet $lead = null): array
    {
        if (null !== $lead) {
            $mission = $lead->getMission() ?? $mission;
        }
        if (!\in_array($mission, self::GROUP_MISSIONS, true)) {
            throw new GameException(t('Gemeinsam fliegen können Flotten nur zum Angreifen oder als Transport-Konvoi.'));
        }
        foreach ($fleets as $fleet) {
            $this->assertControl($fleet, $actor);
        }
        $this->refuseGuildAttack($mission, $galaxy, $system, $position);
        if (FleetMission::Attack === $mission) {
            if (!$cargo->isEmpty()) {
                throw new GameException(t('Zu einem Angriff wird keine Ladung mitgenommen.'));
            }
            $this->withEventLock(fn () => $this->assertAttackShare($fleets, $lead));
        }
        $loads = $this->shareCargo($fleets, $cargo);
        // All or nothing: if one fleet can't start (e.g. can't pay fuel and provisions), none does.
        $this->withEventLock(fn () => $this->preflight($fleets, $actor, $galaxy, $system, $position, $mission, $loads, $speedPercent, $lead));

        $results = [];
        if (null === $lead) {
            if ([] === array_filter($fleets, static fn (Fleet $f): bool => !$f->isAt($galaxy, $system, $position))) {
                throw new GameException(t('Alle ausgewählten Flotten stehen schon an diesem Ziel.'));
            }
            // The slowest fleet leads, the others wait for it.
            usort($fleets, fn (Fleet $a, Fleet $b): int => $this->flightTo($b, $galaxy, $system, $position, $speedPercent) <=> $this->flightTo($a, $galaxy, $system, $position, $speedPercent));
            $lead = array_shift($fleets);
            $results[] = ['ok' => true, 'text' => $this->sendFleet($lead, $actor, $galaxy, $system, $position, $mission, $loads[spl_object_id($lead)], [], $speedPercent, null, true)];
            if (!$lead->isMoving()) {
                return $results;
            }
        }
        foreach ($fleets as $fleet) {
            try {
                $results[] = ['ok' => true, 'text' => $this->sendFleet($fleet, $actor, $galaxy, $system, $position, $mission, $loads[spl_object_id($fleet)], [], $speedPercent, $lead, true)];
            } catch (GameException $e) {
                $results[] = ['ok' => false, 'text' => t('{label}: {error}', ['{label}' => $fleet->getLabel(), '{error}' => $e])];
            }
        }

        return $results;
    }

    /**
     * Shares the cargo among the fleets with cargo space, in proportion to their space.
     *
     * @param list<Fleet> $fleets
     *
     * @return array<int, Resources> spl_object_id => cargo to load
     */
    private function shareCargo(array $fleets, Resources $cargo): array
    {
        $space = [];
        foreach ($fleets as $fleet) {
            $space[spl_object_id($fleet)] = max(0, $this->navigation->cargoCapacity($fleet->getShips()) - $fleet->getCargo()->total());
        }
        $loads = array_map(static fn (): Resources => new Resources(), $space);
        $total = array_sum($space);
        if ($cargo->isEmpty()) {
            return $loads;
        }
        if ($cargo->total() > $total) {
            throw new GameException(t('Die Ladung ({total}) übersteigt den freien Frachtraum der ausgewählten Flotten ({total2}).', ['{total}' => new Num($cargo->total()), '{total2}' => new Num($total)]));
        }
        $left = $cargo;
        $carriers = array_keys(array_filter($space));
        foreach ($carriers as $i => $id) {
            $part = $i === \count($carriers) - 1 ? $left : $cargo->multiply($space[$id] / $total)->floor();
            $loads[$id] = $part;
            $left = $left->subtract($part);
        }

        return $loads;
    }

    /**
     * The other fleets flying together with this one (convoy or joint attack) towards the same stop.
     *
     * @return list<Fleet>
     */
    private function travelGroup(Fleet $fleet): array
    {
        if (!\in_array($fleet->getMission(), self::GROUP_MISSIONS, true) || $fleet->isReturning()) {
            return [];
        }
        $lead = $fleet->getTargetFleet() ?? $fleet;
        $stop = Radar::nextStop($fleet);
        $members = [$lead, ...$this->fleetRepository->findBy(['targetFleet' => $lead, 'mission' => $lead->getMission(), 'returning' => false])];

        return array_values(array_filter(
            $members,
            static fn (Fleet $f): bool => $f !== $fleet && $f->isMoving() && !$f->isReturning() && Radar::nextStop($f) === $stop,
        ));
    }

    /**
     * Checks every fleet of a group before any starts: control, ready, mission and whether
     * its start planet (or its cargo) pays fuel and provisions.
     *
     * @param list<Fleet>           $fleets
     * @param array<int, Resources> $loads
     */
    private function preflight(array $fleets, User $actor, int $galaxy, int $system, int $position, FleetMission $mission, array $loads, float $speedPercent, ?Fleet $lead): void
    {
        $target = $this->planetRepository->findAt($galaxy, $system, $position);
        $post = $this->tradePosts->findAt($galaxy, $system, $position);
        foreach ($fleets as $fleet) {
            $this->em->refresh($fleet);
            $label = $fleet->getLabel();
            try {
                if (!$fleet->isIdle()) {
                    throw new GameException($this->alreadyUnderway($fleet, false));
                }
                $this->assertReadyToLeave($fleet);
                if ($fleet->isAt($galaxy, $system, $position)) {
                    continue; // hovers at the target, costs nothing
                }
                $this->validateMission($mission, $fleet->getOwner(), $target, $fleet->getShips(), $fleet->isNamed(), $post);
                $this->assertNotNewbie($mission, $fleet, $target);
                $ships = $fleet->getShips();
                $distance = $this->navigation->distance($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition(), $galaxy, $system, $position);
                $seconds = $this->navigation->flightSeconds($distance, $this->navigation->slowestSpeed($ships, $fleet->getOwner()), $speedPercent);
                if (null !== $lead && $this->now()->modify("+{$seconds} seconds") > $lead->getArriveAt()) {
                    throw new GameException(t('Zu langsam: {label} ist {value} am Ziel.', ['{label}' => $lead->getLabel(), '{value}' => $this->localTime($lead->getArriveAt())]));
                }
                $oneWay = $mission->isOneWay($fleet->isNamed());
                $travel = $this->navigation->supplies($ships, $seconds, $oneWay)->add(new Resources(hydrogen: $this->navigation->fuel($ships, $distance, $oneWay, $speedPercent)));
                $base = $this->ownPlanetAt($fleet);
                if (null !== $base) {
                    $needed = ($loads[spl_object_id($fleet)] ?? new Resources())->add($travel);
                    if (!$base->getResources()->covers($needed)) {
                        throw new GameException(t('{name} hat nicht genug für Ladung, Treibstoff ({hydrogen} Wasserstoff) und Proviant.', ['{name}' => $base->getName(), '{hydrogen}' => new Num($travel->hydrogen)]));
                    }
                } elseif (null === $this->tradePosts->findAt($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition()) && !$fleet->getCargo()->covers($travel)) {
                    throw new GameException(t('Ihre Ladung reicht nicht für Treibstoff ({hydrogen} Wasserstoff) und Proviant.', ['{hydrogen}' => new Num($travel->hydrogen)]));
                }
            } catch (GameException $e) {
                throw new GameException(t('{label} kann nicht starten: {message} Der ganze Verband bleibt stehen.', ['{label}' => $label, '{message}' => $e->getMessage()]));
            }
        }
    }

    private function flightTo(Fleet $fleet, int $galaxy, int $system, int $position, float $speedPercent): int
    {
        $distance = $this->navigation->distance($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition(), $galaxy, $system, $position);

        return $this->navigation->flightSeconds($distance, $this->navigation->slowestSpeed($fleet->getShips(), $fleet->getOwner()), $speedPercent);
    }

    /**
     * The minimum rule counts all fleets of a player in one attack together (those sent now
     * and those already flying in the joined attack).
     *
     * @param list<Fleet> $fleets
     */
    private function assertAttackShare(array $fleets, ?Fleet $lead): void
    {
        if (null === $this->attackRules) {
            return;
        }
        $inAttack = $fleets;
        if (null !== $lead) {
            $inAttack = [...$inAttack, $lead, ...$this->fleetRepository->findAttackJoiners($lead)];
        }
        $byOwner = [];
        foreach ($inAttack as $fleet) {
            $id = (int) $fleet->getOwner()->getId();
            $byOwner[$id]['owner'] = $fleet->getOwner();
            $byOwner[$id]['fleets'][spl_object_id($fleet)] = $fleet;
        }
        foreach ($byOwner as ['owner' => $owner, 'fleets' => $own]) {
            $sent = [];
            foreach ($own as $fleet) {
                foreach ($fleet->getShips() as $type => $count) {
                    $sent[$type] = ($sent[$type] ?? 0) + $count;
                }
            }
            if (null !== $reason = AttackRules::rejection($sent, $this->attackRules->allShips($owner))) {
                throw new GameException(t('{player}: {reason}', ['{player}' => $owner->getUsername(), '{reason}' => $reason]));
            }
        }
    }

    /**
     * Freighters have no business in a warfleet: loot is carried by salvage ships.
     *
     * @param array<string, int> $ships
     */
    public static function freightersInWarfleet(array $ships): ?TranslatableInterface
    {
        $freighters = array_filter([ShipType::Freighter, ShipType::LargeFreighter], static fn (ShipType $t): bool => ($ships[$t->value] ?? 0) > 0);

        return [] === $freighters ? null : t('{value} gehören nicht in eine Kriegsflotte. Für die Beute nimm Bergungsschiffe mit (je {salvageCapacity} Frachtraum) – lass die Frachter zu Hause oder teile die Flotte auf.', ['{value}' => implode(' und ', array_map(static fn (ShipType $t): string => $t->label(), $freighters)), '{salvageCapacity}' => new Num(ShipType::Salvager->salvageCapacity())]);
    }

    /** Newbie protection; a delivery for a paid transport request may still fly. */
    private function assertNotNewbie(FleetMission $mission, Fleet $fleet, ?Planet $target): void
    {
        $reason = NewbieProtection::refusal($mission, $fleet->getOwner(), $target);
        if (null !== $reason && !(FleetMission::Transport === $mission && true === $this->transportRequests?->isPaidDelivery($fleet))) {
            throw new GameException($reason);
        }
    }

    /**
     * Planets of a player on vacation can't be attacked – except invaded planets that
     * don't fully belong to him yet. Spying on them is allowed.
     */
    private function vacationProtects(?Planet $target, ?\DateTimeImmutable $launchedAt = null): ?TranslatableInterface
    {
        if (null === $target || !$target->getOwner()->isOnVacation($this->now()) || $target->isIntegrating($this->now())) {
            return null;
        }
        // An attack that was already flying when the vacation began still strikes: going on
        // vacation is no way to dodge an attack on its way.
        $since = $target->getOwner()->getVacationSince();
        if (null !== $launchedAt && null !== $since && $since > $launchedAt) {
            return null;
        }

        return t('{player} ist im Urlaub bis {value} – seine Planeten sind so lange geschützt.', ['{player}' => $target->getOwner()->getUsername(), '{value}' => $this->localTime($target->getOwner()->getVacationUntil())]);
    }

    /** Whether the player's fleets may wait at this planet without the 24 hour limit (own, alliance, bond, protection contract). */
    private function mayStayAt(User $player, Planet $planet): bool
    {
        return ($this->diplomacy?->mayStay($player, $planet->getOwner()) ?? ($player->isSameAs($planet->getOwner()) || $player->isAllyOf($planet->getOwner())))
            || ($this->contracts?->protects($player, $planet) ?? false);
    }

    /** Alliance mates and partners of a bond or non-aggression pact are no targets. */
    /** Traders and guardians spare settlers and traders who aren't criminal (see HeroPoints). */
    private function lifeFormRefusal(User $attacker, User $victim): ?TranslatableInterface
    {
        return HeroPoints::attackRefusal($attacker, $victim, $this->diplomacy?->atWar($attacker, $victim) ?? false);
    }

    private function mayAttack(User $attacker, User $victim): bool
    {
        if ($victim->isSystem() && TradeGuild::NAME === $victim->getUsername()) {
            return false; // the trade guild is no target
        }

        return $this->diplomacy?->mayAttack($attacker, $victim) ?? (!$attacker->isSameAs($victim) && !$attacker->isAllyOf($victim));
    }

    // ------------------------------------------------------------------
    // Probes: the only ships that start straight from a hangar
    // ------------------------------------------------------------------

    /**
     * Sends one scout to spy on a slot, from the own planet nearest to it that has
     * a scout in its hangar. The scout is used up on arrival.
     */
    public function quickSpy(User $player, int $galaxy, int $system, int $position): TranslatableInterface
    {
        if (!$this->colonizer->isValidSlot($galaxy, $system, $position)) {
            throw new GameException(t('Diese Koordinaten gibt es nicht.'));
        }

        // Nearest planet with a scout in the hangar (all scouts of a player are equally fast).
        $origin = null;
        $best = \PHP_INT_MAX;
        foreach ($player->getPlanets() as $planet) {
            if ($planet->getShipCount(ShipType::Scout) < 1) {
                continue;
            }
            $distance = $this->navigation->distance($planet->getGalaxy(), $planet->getSystem(), $planet->getPosition(), $galaxy, $system, $position);
            if ($distance < $best) {
                [$origin, $best] = [$planet, $distance];
            }
        }
        if (null === $origin) {
            throw new GameException(t('In deinen Hangars steht keine Sonde. Baue welche in der Werft.'));
        }

        $fleet = null;
        // A real closure (not fn()): arrow functions capture by value and would lose $fleet.
        $this->withEventLock(function () use ($origin, $galaxy, $system, $position, &$fleet): void {
            $this->planets->transaction($origin, function (Planet $origin, \DateTimeImmutable $now) use ($galaxy, $system, $position, &$fleet): void {
            if ($origin->getShipCount(ShipType::Scout) < 1) {
                throw new GameException(t('Die Sonde ist inzwischen nicht mehr im Hangar.'));
            }
            $origin->addShips(ShipType::Scout, -1);
            $fleet = new Fleet($origin->getOwner(), null, $origin->getGalaxy(), $origin->getSystem(), $origin->getPosition(), [ShipType::Scout->value => 1]);
            $this->launch($fleet, $origin, FleetMission::Spy, $galaxy, $system, $position, new Resources(), $now);
            $this->em->persist($fleet);
            });
        });

        return t('Sonde von {name} [{coords}] gestartet nach [{galaxy}:{system}:{position}], Ankunft {value}.', ['{name}' => $origin->getName(), '{coords}' => $origin->getCoordinates(), '{galaxy}' => $galaxy, '{system}' => $system, '{position}' => $position, '{value}' => $this->localTime($fleet->getArriveAt())]);
    }

    /**
     * "Kolonisieren" in the universe: one colony ship flies straight out of the hangar of
     * the planet the player is looking at, without being put into a fleet first.
     */
    public function quickColonize(Planet $origin, int $galaxy, int $system, int $position): TranslatableInterface
    {
        if ($origin->getShipCount(ShipType::ColonyShip) < 1) {
            throw new GameException(t('Im Hangar von {name} [{coords}] steht kein Kolonieschiff. Baue eins in der Werft oder wähle links einen Planeten mit Kolonieschiff.', ['{name}' => $origin->getName(), '{coords}' => $origin->getCoordinates()]));
        }

        $fleet = null;
        // A real closure (not fn()): arrow functions capture by value and would lose $fleet.
        $this->withEventLock(function () use ($origin, $galaxy, $system, $position, &$fleet): void {
            $this->planets->transaction($origin, function (Planet $origin, \DateTimeImmutable $now) use ($galaxy, $system, $position, &$fleet): void {
                if ($origin->getShipCount(ShipType::ColonyShip) < 1) {
                    throw new GameException(t('Das Kolonieschiff ist inzwischen nicht mehr im Hangar.'));
                }
                $fleet = new Fleet($origin->getOwner(), null, $origin->getGalaxy(), $origin->getSystem(), $origin->getPosition(), [ShipType::ColonyShip->value => 1]);
                $this->launch($fleet, $origin, FleetMission::Colonize, $galaxy, $system, $position, new Resources(), $now);
                $origin->addShips(ShipType::ColonyShip, -1);
                $this->em->persist($fleet);
            });
        });

        return t('Kolonieschiff von {name} [{coords}] gestartet nach [{galaxy}:{system}:{position}], Ankunft {value}.', ['{name}' => $origin->getName(), '{coords}' => $origin->getCoordinates(), '{galaxy}' => $galaxy, '{system}' => $system, '{position}' => $position, '{value}' => $this->localTime($fleet->getArriveAt())]);
    }

    /**
     * Sends one scout from the nearest own hangar towards a visible foreign fleet:
     * it meets the fleet at its next stop, before the fleet gets there, and reports
     * what the fleet consists of.
     */
    public function spyFleet(User $player, Fleet $target): TranslatableInterface
    {
        if ($target->getOwner()->isSameAs($player) || !$target->isMoving() || !$this->radar->knows($player, $target)) {
            throw new GameException(t('Diese Flotte ist nicht auf deinem Radar.'));
        }
        [$galaxy, $system, $position] = Radar::nextStop($target);

        $origin = null;
        $best = \PHP_INT_MAX;
        foreach ($player->getPlanets() as $planet) {
            if ($planet->getShipCount(ShipType::Scout) < 1) {
                continue;
            }
            $distance = $this->navigation->distance($planet->getGalaxy(), $planet->getSystem(), $planet->getPosition(), $galaxy, $system, $position);
            if ($distance < $best) {
                [$origin, $best] = [$planet, $distance];
            }
        }
        if (null === $origin) {
            throw new GameException(t('In deinen Hangars steht keine Sonde. Baue welche in der Werft.'));
        }

        $probe = null;
        $this->withEventLock(function () use ($origin, $target, $galaxy, $system, $position, &$probe): void {
            $this->em->refresh($target);
            $this->planets->transaction($origin, function (Planet $origin, \DateTimeImmutable $now) use ($target, $galaxy, $system, $position, &$probe): void {
                if ($origin->getShipCount(ShipType::Scout) < 1) {
                    throw new GameException(t('Die Sonde ist inzwischen nicht mehr im Hangar.'));
                }
                $origin->addShips(ShipType::Scout, -1);
                $probe = new Fleet($origin->getOwner(), null, $origin->getGalaxy(), $origin->getSystem(), $origin->getPosition(), [ShipType::Scout->value => 1]);
                $this->travel($probe, $origin, FleetMission::Spy, $galaxy, $system, $position, new Resources(), $now, $target, $target->getNextEventAt());
                $this->em->persist($probe);
            });
        });

        return t('Sonde von {name} [{coords}] fliegt der Flotte von {player} entgegen, Treffpunkt [{galaxy}:{system}:{position}] um {value}.', ['{name}' => $origin->getName(), '{coords}' => $origin->getCoordinates(), '{player}' => $target->getOwner()->getUsername(), '{galaxy}' => $galaxy, '{system}' => $system, '{position}' => $position, '{value}' => $this->localTime($probe->getArriveAt())]);
    }

    // ------------------------------------------------------------------
    // Named fleets
    // ------------------------------------------------------------------

    /** @param array<string, int> $ships */
    public function create(Planet $planet, string $name, array $ships): void
    {
        $name = $this->validName($name);
        $this->withEventLock(fn () => $this->planets->transaction($planet, function (Planet $planet) use ($name, $ships): void {
            $this->assertFleetCapacity($planet->getOwner());
            // An empty fleet is allowed: a rally point to gather ships at later.
            $ships = [] === array_filter(array_map('intval', $ships), static fn (int $n): bool => $n > 0) ? [] : $this->takeFromHangar($planet, $ships);
            $this->em->persist(new Fleet($planet->getOwner(), $name, $planet->getGalaxy(), $planet->getSystem(), $planet->getPosition(), $ships));
        }));
    }

    public function rename(Fleet $fleet, User $actor, string $name): void
    {
        $this->assertOwner($fleet, $actor);
        $fleet->setName($this->validName($name));
        $this->em->flush();
    }

    /**
     * Moves ships between an idle fleet and the hangar of the own planet it is at.
     *
     * @param array<string, int> $ships
     */
    public function transfer(Fleet $fleet, User $actor, array $ships, bool $intoFleet): void
    {
        $this->withEventLock(function () use ($fleet, $actor, $ships, $intoFleet): void {
            $this->em->refresh($fleet);
            $base = $this->idleAtOwnPlanet($fleet, $actor);
            $this->planets->transaction($base, function (Planet $base) use ($fleet, $ships, $intoFleet): void {
                if ($intoFleet) {
                    foreach ($this->takeFromHangar($base, $ships) as $type => $count) {
                        $fleet->addShips(ShipType::from($type), $count);
                    }

                    return;
                }
                $moved = false;
                foreach ($ships as $type => $count) {
                    $shipType = ShipType::tryFrom((string) $type);
                    $count = min((int) $count, null === $shipType ? 0 : $fleet->getShipCount($shipType));
                    if ($count > 0) {
                        $fleet->removeShips($shipType, $count);
                        $base->addShips($shipType, $count);
                        $moved = true;
                    }
                }
                if (!$moved) {
                    throw new GameException(t('Wähle mindestens ein Schiff aus.'));
                }
                // Without ships the fleet stays as an empty fleet; its cargo goes into storage.
                if (!$fleet->hasShips()) {
                    $base->setResources($base->getResources()->add($fleet->getCargo()));
                    $fleet->setCargo(new Resources());
                }
            });
        });
    }

    /**
     * Splits ships off an idle fleet into a new fleet at the same position (anywhere).
     * The cargo stays with the original fleet.
     *
     * @param array<string, int> $ships
     */
    public function split(Fleet $fleet, User $actor, string $name, array $ships): void
    {
        $name = $this->validName($name);
        $this->withEventLock(function () use ($fleet, $actor, $name, $ships): void {
            $this->em->refresh($fleet);
            $this->assertOwner($fleet, $actor);
            if (!$fleet->isIdle()) {
                throw new GameException(t('Eine Flotte kann nur aufgeteilt werden, solange sie nicht unterwegs ist.'));
            }

            $taken = [];
            foreach ($ships as $type => $count) {
                $shipType = ShipType::tryFrom((string) $type);
                $count = (int) $count;
                if (null === $shipType || $count <= 0) {
                    continue;
                }
                if ($count > $fleet->getShipCount($shipType)) {
                    throw new GameException(t('Die Flotte hat nur {value} × {shipType}.', ['{value}' => $fleet->getShipCount($shipType), '{shipType}' => t($shipType->label())]));
                }
                $taken[$shipType->value] = $count;
            }
            if ([] === $taken) {
                throw new GameException(t('Wähle mindestens ein Schiff aus.'));
            }
            if (array_sum($taken) >= array_sum($fleet->getShips())) {
                throw new GameException(t('Mindestens ein Schiff muss in der ursprünglichen Flotte bleiben. Zum Umbenennen nutze „Umbenennen“.'));
            }
            $this->assertFleetCapacity($fleet->getOwner());
            foreach ($taken as $type => $count) {
                $fleet->removeShips(ShipType::from($type), $count);
            }

            $this->em->persist(new Fleet($fleet->getOwner(), $name, $fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition(), $taken));
            $this->em->flush();
        });
    }

    /**
     * Takes ships out of a waiting fleet: at an own planet they go into its hangar,
     * anywhere else they form a new fleet at the same place.
     *
     * @param array<string, int> $ships
     *
     * @return TranslatableInterface what happened
     */
    public function removeShips(Fleet $fleet, User $actor, array $ships): TranslatableInterface
    {
        $this->assertOwner($fleet, $actor);
        if (null !== $this->ownPlanetAt($fleet)) {
            $this->transfer($fleet, $actor, $ships, false);

            return t('hat Schiffe in den Hangar abgegeben');
        }
        $name = $this->freeName($fleet->getOwner(), ($fleet->getName() ?? 'Flotte').' (abgetrennt)');
        $this->split($fleet, $actor, $name, $ships);

        return t('hat Schiffe abgegeben – sie warten als neue Flotte „{name}“ am selben Ort', ['{name}' => $name]);
    }

    /** One fleet per level of fleet control center (summed over all planets); a new fleet needs a free one. */
    private function assertFleetCapacity(User $owner): void
    {
        $capacity = Economy::fleetCapacity($owner);
        $fleets = $this->fleetRepository->countNamed($owner);
        if ($fleets >= $capacity) {
            throw new GameException(0 === $capacity
                ? t('Für eine Flotte brauchst du ein Flottenkontrollzentrum. Jede Stufe auf einem deiner Planeten erlaubt eine Flotte.')
                : t('Du befehligst schon {fleets} von {capacity} Flotten. Jede weitere Stufe Flottenkontrollzentrum erlaubt eine Flotte mehr – oder lege Flotten zusammen.', ['{fleets}' => $fleets, '{capacity}' => $capacity]));
        }
    }

    /** A fleet name not used by the player yet ("Name", "Name 2", …). */
    private function freeName(User $owner, string $wanted): string
    {
        $wanted = mb_substr($wanted, 0, 36);
        $taken = array_map(static fn (Fleet $f): string => mb_strtolower((string) $f->getName()), $this->fleetRepository->findByOwner($owner));
        $name = $wanted;
        for ($i = 2; \in_array(mb_strtolower($name), $taken, true); ++$i) {
            $name = $wanted.' '.$i;
        }

        return $name;
    }

    /**
     * Merges fleets waiting at the same place into the first one (ships and cargo).
     *
     * @param list<Fleet> $fleets
     */
    public function merge(array $fleets, User $actor): Fleet
    {
        if (\count($fleets) < 2) {
            throw new GameException(t('Wähle mindestens zwei Flotten zum Zusammenlegen aus.'));
        }
        $this->withEventLock(function () use ($fleets, $actor): void {
            foreach ($fleets as $fleet) {
                $this->em->refresh($fleet);
                $this->assertOwner($fleet, $actor);
                if (!$fleet->isIdle()) {
                    throw new GameException(t('{label} ist unterwegs und kann nicht zusammengelegt werden.', ['{label}' => $fleet->getLabel()]));
                }
                if (!$fleet->isAt($fleets[0]->getGalaxy(), $fleets[0]->getSystem(), $fleets[0]->getPosition())) {
                    throw new GameException(t('Nur Flotten am selben Ort können zusammengelegt werden.'));
                }
            }
            foreach (\array_slice($fleets, 1) as $other) {
                $fleets[0]->merge($other);
                $this->fleetRepository->forget($other);
            }
            $this->em->flush();
        });

        return $fleets[0];
    }

    public function dissolve(Fleet $fleet, User $actor): void
    {
        $this->withEventLock(function () use ($fleet, $actor): void {
            $this->em->refresh($fleet);
            // An empty fleet can be dissolved anywhere (unless ships are on their way to it).
            if (!$fleet->hasShips() && $fleet->getCargo()->isEmpty()) {
                $this->assertOwner($fleet, $actor);
                if (!$fleet->isIdle() || $this->fleetRepository->countJoining($fleet) > 0) {
                    throw new GameException(t('Zu dieser Flotte fliegen noch Schiffe – sie kann erst danach aufgelöst werden.'));
                }
                $this->fleetRepository->forget($fleet);
                $this->em->flush();

                return;
            }
            $base = $this->idleAtOwnPlanet($fleet, $actor);
            $this->planets->transaction($base, function (Planet $base) use ($fleet): void {
                $this->unload($base, $fleet);
                $this->fleetRepository->forget($fleet);
            });
        });
    }

    /** Unloads the cargo of an idle fleet onto the own planet it is at. */
    public function unloadCargo(Fleet $fleet, User $actor): void
    {
        $this->withEventLock(function () use ($fleet, $actor): void {
            $this->em->refresh($fleet);
            $base = $this->idleAtOwnPlanet($fleet, $actor);
            $this->planets->transaction($base, function (Planet $base) use ($fleet): void {
                $base->setResources($base->getResources()->add($fleet->getCargo()));
                $fleet->setCargo(new Resources());
            });
        });
    }

    /**
     * Sends an idle named fleet on a mission. If it is at one of its owner's
     * planets, cargo is loaded there and that planet pays fuel and supplies;
     * elsewhere the fleet pays them from its own cargo.
     *
     * A fusion of the planet the fleet is already waiting at happens at once,
     * without any flight.
     *
     * @return TranslatableInterface what happened (for the player)
     */
    /** @param array<string, float> $order goods to buy (mission "Handeln") */
    public function sendFleet(Fleet $fleet, User $actor, int $galaxy, int $system, int $position, FleetMission $mission, Resources $cargo, array $order = [], float $speedPercent = 100.0, ?Fleet $lead = null, bool $shareChecked = false): TranslatableInterface
    {
        if (null !== $lead) {
            // Joining an attack or convoy: same target and mission, arriving together with the leading fleet.
            [$galaxy, $system, $position] = [(int) $lead->getTargetGalaxy(), (int) $lead->getTargetSystem(), (int) $lead->getTargetPosition()];
            $mission = $lead->getMission() ?? $mission;
        }
        $this->refuseGuildAttack($mission, $galaxy, $system, $position);
        $result = t('Flotte „{name}“ gestartet: {mission} nach [{coords}].', ['{name}' => $fleet->getName(), '{mission}' => t($mission->label()), '{coords}' => \sprintf('%d:%d:%d', $galaxy, $system, $position)]);
        $this->withEventLock(function () use ($fleet, $actor, $galaxy, $system, $position, $mission, $cargo, $order, $speedPercent, $lead, $shareChecked, &$result): void {
            $this->em->refresh($fleet);
            $this->assertControl($fleet, $actor);
            if (!$fleet->isIdle()) {
                throw new GameException($this->alreadyUnderway($fleet));
            }
            $this->assertReadyToLeave($fleet);
            if (!$fleet->getOwner()->isSameAs($actor)) {
                $this->inbox->send($fleet->getOwner(), t('Flotte „{name}“ von {player} gesteuert', ['{name}' => $fleet->getName(), '{player}' => $actor->getUsername()]), t('{player} hat deine freigegebene Flotte „{name}“ mit dem Auftrag „{mission}“ nach [{galaxy}:{system}:{position}] geschickt.', ['{player}' => $actor->getUsername(), '{name}' => $fleet->getName(), '{mission}' => t($mission->label()), '{galaxy}' => $galaxy, '{system}' => $system, '{position}' => $position]));
            }

            $target = $this->planetRepository->findAt($galaxy, $system, $position);

            // An empty fleet (a rally point) moves at once, without flight time and fuel.
            if (!$fleet->hasShips()) {
                if (!\in_array($mission, [FleetMission::Station, FleetMission::Jump], true)) {
                    throw new GameException(t('Eine leere Flotte kann nur stationieren oder springen – sie wird dabei ohne Flugzeit verlegt.'));
                }
                if (!Universe::isValidSlot($galaxy, $system, $position)) {
                    throw new GameException(t('Diese Koordinaten gibt es nicht.'));
                }
                if (FleetMission::Jump === $mission && null === $target) {
                    throw new GameException(t('Springen kannst du nur zu einem besiedelten Planeten.'));
                }
                $fleet->relocate($galaxy, $system, $position);
                $this->em->flush();
                $result = t('Die leere Flotte „{name}“ wurde ohne Flugzeit nach [{galaxy}:{system}:{position}] verlegt.', ['{name}' => $fleet->getName(), '{galaxy}' => $galaxy, '{system}' => $system, '{position}' => $position]);

                return;
            }

            if (FleetMission::Fuse === $mission && null !== $target && $target->covers($fleet->getPosition())
                && $target->getGalaxy() === $fleet->getGalaxy() && $target->getSystem() === $fleet->getSystem()) {
                $result = $this->fuseInPlace($fleet, $target);

                return;
            }

            if (FleetMission::Attack === $mission && !$shareChecked) {
                $this->assertAttackShare([$fleet], $lead);
            }
            if (null !== $lead) {
                $this->em->refresh($lead);
                if (!$lead->isMoving() || !\in_array($lead->getMission(), self::GROUP_MISSIONS, true) || $lead->isReturning() || null !== $lead->getTargetFleet() || $lead->getArriveAt() <= $this->now()) {
                    throw new GameException(t('Diesem Angriff oder Konvoi kann man nichts mehr dazuschicken.'));
                }
                if (!$lead->isControllableBy($actor)) {
                    throw new GameException(t('Weitere Flotten schickt nur, wer den Angriff führt – die anderen geben ihm ihre Flotten frei.'));
                }
            }
            // Already at the target of its group: hover there until the others arrive.
            if (null !== $lead && $fleet->isAt($galaxy, $system, $position)) {
                $fleet->hoverUntil($lead, $this->now());
                $this->em->flush();
                $result = t('Flotte „{name}“ steht schon am Ziel und schwebt dort, bis {label} ankommt.', ['{name}' => $fleet->getName(), '{label}' => $lead->getLabel()]);

                return;
            }
            $launch = function (?Planet $base, \DateTimeImmutable $now) use ($fleet, $mission, $galaxy, $system, $position, $cargo, $order, $speedPercent, $lead): void {
                $this->launch($fleet, $base, $mission, $galaxy, $system, $position, $cargo, $now, $order, $speedPercent);
                if (null !== $lead) {
                    if ($fleet->getArriveAt() > $lead->getArriveAt()) {
                        throw new GameException(t('Zu langsam: {label} käme erst {value} an, {label2} ist {value2} dort.', ['{label}' => $fleet->getLabel(), '{value}' => $this->localTime($fleet->getArriveAt()), '{label2}' => $lead->getLabel(), '{value2}' => $this->localTime($lead->getArriveAt())]));
                    }
                    $fleet->arriveTogetherWith($lead);
                }
            };

            $base = $this->ownPlanetAt($fleet);
            if (null !== $base) {
                $this->planets->transaction($base, fn (Planet $base, \DateTimeImmutable $now) => $launch($base, $now));

                return;
            }
            $launch(null, $this->now());
            $this->em->flush();
        });
        if (null !== $lead && $fleet->isMoving() && !$fleet->isAt($galaxy, $system, $position)) {
            $result = FleetMission::Attack === $mission
                ? t('Flotte „{name}“ tritt dem Angriff von {player} auf [{targetCoordinates}] bei.', ['{name}' => $fleet->getName(), '{player}' => $lead->getOwner()->getUsername(), '{targetCoordinates}' => $lead->getTargetCoordinates()])
                : t('Flotte „{name}“ fliegt im Konvoi mit {label} nach [{targetCoordinates}].', ['{name}' => $fleet->getName(), '{label}' => $lead->getLabel(), '{targetCoordinates}' => $lead->getTargetCoordinates()]);
        }

        if (FleetMission::Attack === $mission && $fleet->isMoving()) {
            $this->callGuardians($fleet);
            if (null === $lead) {
                $this->mailAttackWarning($fleet);
            }
        }

        if ($fleet->isMoving()) {
            $result = new Lines([
                $result,
                $speedPercent < 100 ? t('Mit {speed} % Geschwindigkeit.', ['{speed}' => new Num($speedPercent, 2)]) : null,
                t('Ankunft: {time}.', ['{time}' => $this->localTime($fleet->getArriveAt())]),
            ], ' ');
        }

        return $result;
    }

    /** Why a fleet in flight can't take a new order – with where it is heading, so the player sees which one it is. */
    private function alreadyUnderway(Fleet $fleet, bool $withLabel = true): TranslatableInterface
    {
        $where = $fleet->isReturning()
            ? t('kehrt gerade heim')
            : t('{mission} nach [{coords}]', ['{mission}' => t((string) $fleet->getMission()?->label()), '{coords}' => (string) $fleet->getTargetCoordinates()]);

        return $withLabel
            ? t('{label} ist schon unterwegs ({where}). Wähle eine Flotte, die steht, oder rufe diese erst zurück.', ['{label}' => $fleet->getLabel(), '{where}' => $where])
            : t('Sie ist schon unterwegs ({where}). Nimm sie aus der Auswahl oder rufe sie erst zurück.', ['{where}' => $where]);
    }

    /** The guild can't be attacked – said first, whatever fleets were picked. */
    private function refuseGuildAttack(FleetMission $mission, int $galaxy, int $system, int $position): void
    {
        if (FleetMission::Attack !== $mission) {
            return;
        }
        $owner = $this->planetRepository->findAt($galaxy, $system, $position)?->getOwner() ?? $this->tradePosts->findAt($galaxy, $system, $position)?->getOwner();
        if (null !== $owner && $owner->isSystem() && TradeGuild::NAME === $owner->getUsername()) {
            throw new GameException(t('Die Zentralbörsen der Handelsgilde können nicht angegriffen werden.'));
        }
    }

    /**
     * A fleet may not leave while ships are still flying to join it
     * ("Schiffe zusammenziehen") – otherwise they would reach it wherever it went, without flight time.
     * Called under the fleet event lock.
     */
    private function assertReadyToLeave(Fleet $fleet): void
    {
        $joining = $this->fleetRepository->countJoining($fleet);
        if ($joining > 0) {
            throw new GameException(t('Zu {label} fliegen noch Schiffe (Schiffe zusammenziehen). Warte, bis sie angekommen sind, oder rufe sie zurück.', ['{label}' => $fleet->getLabel()]));
        }
    }

    /** E-mail to the attacked player, if his radar sees the attack and he wants such mails. */
    private function mailAttackWarning(Fleet $attacker): void
    {
        $target = $this->planetRepository->findAt((int) $attacker->getTargetGalaxy(), (int) $attacker->getTargetSystem(), (int) $attacker->getTargetPosition());
        if (null === $target || null === $this->gameMailer || !$target->getOwner()->wantsMail('attack') || !$target->getOwner()->allowAttackMail($this->now())) {
            return;
        }
        $this->gameMailer->notify($target->getOwner(), 'attack', t('Angriff auf {name}', ['{name}' => $target->getName()]), t("{player} greift deinen Planeten {name} [{coords}] an.\nEinschlag: {value}\n\nIm Spiel siehst du den Angriff auf der Übersicht und kannst die Flotte ausspionieren.", ['{player}' => $attacker->getOwner()->getUsername(), '{name}' => $target->getName(), '{coords}' => $target->getCoordinates(), '{value}' => $this->localTime($attacker->getArriveAt())]));
    }

    /** Systems around an attacked planet in which guardians receive the distress call. */
    public const int DISTRESS_RANGE = 10;

    /**
     * Settlers and traders under attack call for help: every guardian with a planet up to
     * DISTRESS_RANGE systems away (same galaxy) gets a message, so he can intercept.
     */
    private function callGuardians(Fleet $attacker): void
    {
        $target = $this->planetRepository->findAt($attacker->getTargetGalaxy(), $attacker->getTargetSystem(), $attacker->getTargetPosition());
        if (null === $target || !\in_array($target->getOwner()->getLifeForm(), [LifeForm::Settler, LifeForm::Trader], true)) {
            return;
        }
        $victim = $target->getOwner();
        $guardians = [];
        foreach ($this->planetRepository->findOfLifeFormNear(LifeForm::Guardian, $target->getGalaxy(), $target->getSystem(), self::DISTRESS_RANGE) as $planet) {
            $guardian = $planet->getOwner();
            if (!$guardian->isSameAs($attacker->getOwner()) && !$guardian->isSameAs($victim)) {
                $guardians[$guardian->getId()] = $guardian;
            }
        }
        foreach ($guardians as $guardian) {
            $this->inbox->send($guardian, t('Hilferuf von {player}', ['{player}' => $victim->getUsername()]), t("{player} ({label}) wird angegriffen!\n\nAngreifer: {player2}\nZiel: {name} [{coords}]\nEinschlag: {value}\n\nAls Wächter kannst du die angreifende Flotte auf dem Radar abfangen, wenn dein Radar das Zielsystem abdeckt.", ['{player}' => $victim->getUsername(), '{label}' => t($victim->getLifeForm()->label()), '{player2}' => $attacker->getOwner()->getUsername(), '{name}' => $target->getName(), '{coords}' => $target->getCoordinates(), '{value}' => $this->localTime($attacker->getArriveAt())]));
        }
        $this->em->flush();
    }

    /** The fleet's fusionators weld the planet it is waiting at, immediately. */
    private function fuseInPlace(Fleet $fleet, Planet $target): TranslatableInterface
    {
        $this->validateMission(FleetMission::Fuse, $fleet->getOwner(), $target, $fleet->getShips(), true);

        $sent = $fleet->getShipCount(ShipType::Fusionator);
        $used = $this->fusion->fuseOnArrival($target, $sent);
        $fleet->removeShips(ShipType::Fusionator, $used);
        if (!$fleet->hasShips()) {
            $this->fleetRepository->forget($fleet);
        }
        $this->em->flush();

        return new Lines([
            t('{name} wurde sofort fusioniert, {used} von {sent} Fusionatoren wurden verbraucht.', ['{name}' => $target->getName(), '{used}' => $used, '{sent}' => $sent]),
            $used < $sent ? t('Die übrigen bleiben in der Flotte „{name}“.', ['{name}' => $fleet->getName()]) : null,
        ], ' ');
    }

    /**
     * "Schiffe zusammenziehen": ships of the chosen types fly from all own planets
     * to the fleet, if they can reach it within $maxMinutes. Every ship type flies
     * at its own speed; each planet pays fuel and supplies for the one-way flight.
     *
     * @param list<ShipType> $types
     *
     * @return list<TranslatableInterface> what was sent where (for the player)
     */
    public function gather(Fleet $fleet, User $actor, int $maxMinutes, array $types): array
    {
        if ($maxMinutes < 1) {
            throw new GameException(t('Bitte gib eine maximale Flugzeit von mindestens einer Minute an.'));
        }
        if ([] === $types) {
            throw new GameException(t('Wähle mindestens einen Schiffstyp aus.'));
        }

        $report = [];
        $this->withEventLock(function () use ($fleet, $actor, $maxMinutes, $types, &$report): void {
            $this->em->refresh($fleet);
            $this->assertOwner($fleet, $actor);
            if (!$fleet->isIdle()) {
                throw new GameException(t('Schiffe können nur zu einer Flotte gezogen werden, die nicht unterwegs ist.'));
            }
            $anyPlanet = $actor->getPlanets()->first();
            if (!$anyPlanet instanceof Planet) {
                throw new GameException(t('Du besitzt keinen Planeten.'));
            }

            // One transaction keeps all of the player's planets up to date.
            $this->planets->transaction($anyPlanet, function () use ($fleet, $actor, $maxMinutes, $types, &$report): void {
                $now = $this->now();
                foreach ($actor->getPlanets() as $planet) {
                    foreach ($types as $type) {
                        $count = $planet->getShipCount($type);
                        if ($count < 1 || $type->isStationary() || $type->isProbe()) {
                            continue;
                        }
                        $ships = [$type->value => $count];

                        if ($planet->covers($fleet->getPosition()) && $planet->getGalaxy() === $fleet->getGalaxy() && $planet->getSystem() === $fleet->getSystem()) {
                            $planet->addShips($type, -$count);
                            $fleet->addShips($type, $count);
                            $report[] = t('{count} × {type} von {name} sofort übernommen', ['{count}' => $count, '{type}' => t($type->label()), '{name}' => $planet->getName()]);
                            continue;
                        }

                        $distance = $this->navigation->distance($planet->getGalaxy(), $planet->getSystem(), $planet->getPosition(), $fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition());
                        $seconds = $this->navigation->flightSeconds($distance, $this->economy->shipSpeed($type, $actor));
                        if ($seconds > $maxMinutes * 60) {
                            continue;
                        }

                        $needed = $this->navigation->supplies($ships, $seconds, oneWay: true)
                            ->add(new Resources(hydrogen: $this->navigation->fuel($ships, $distance, oneWay: true)));
                        if (!$planet->getResources()->covers($needed)) {
                            $report[] = t('{count} × {type} auf {name}: nicht genug Treibstoff oder Proviant', ['{count}' => $count, '{type}' => t($type->label()), '{name}' => $planet->getName()]);
                            continue;
                        }
                        $planet->setResources($planet->getResources()->subtract($needed));
                        $planet->addShips($type, -$count);

                        $group = new Fleet($actor, null, $planet->getGalaxy(), $planet->getSystem(), $planet->getPosition(), $ships);
                        $group->depart(FleetMission::Gather, $fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition(), $now, $seconds, $fleet);
                        $this->em->persist($group);
                        $report[] = t('{count} × {type} von {name}, Ankunft in {value}', ['{count}' => $count, '{type}' => t($type->label()), '{name}' => $planet->getName(), '{value}' => GameExtension::formatDuration($seconds)]);
                    }
                }
            });
        });

        if ([] === $report) {
            throw new GameException(t('Keine passenden Schiffe erreichen die Flotte innerhalb von {maxMinutes} Minuten.', ['{maxMinutes}' => $maxMinutes]));
        }

        return $report;
    }

    /**
     * Sends an idle fleet to meet a visible foreign fleet at its next stop, just
     * before it gets there: "Abfangen" an attacker (Wächter and Piraten) or
     * "Überfallen" a resource transport (Piraten only).
     */
    public function hunt(Fleet $fleet, User $actor, Fleet $prey, FleetMission $mission, float $speedPercent = 100.0): TranslatableInterface
    {
        $result = '';
        $this->withEventLock(function () use ($fleet, $actor, $prey, $mission, $speedPercent, &$result): void {
            $this->em->refresh($fleet);
            $this->em->refresh($prey);
            $this->assertControl($fleet, $actor);
            if (!$fleet->isIdle()) {
                throw new GameException($this->alreadyUnderway($fleet));
            }
            if (null !== $ban = $this->attackBans?->refusal()) {
                throw new GameException($ban);
            }
            $this->attackRules?->assertMayAttack($fleet);
            if (null !== $reason = self::freightersInWarfleet($fleet->getShips())) {
                throw new GameException($reason);
            }
            $owner = $fleet->getOwner();
            $lifeForm = $owner->getLifeForm();

            if (FleetMission::Intercept === $mission) {
                if (!\in_array($lifeForm, [LifeForm::Guardian, LifeForm::Pirate], true)) {
                    throw new GameException(t('Nur Wächter und Piraten können angreifende Flotten abfangen.'));
                }
                if (FleetMission::Attack !== $prey->getMission() || $prey->isReturning()) {
                    throw new GameException(t('Abfangen kannst du nur Flotten, die gerade angreifen.'));
                }
            } elseif (FleetMission::Raid === $mission) {
                if (LifeForm::Pirate !== $lifeForm) {
                    throw new GameException(t('Nur Piraten können Transporte überfallen.'));
                }
                if (!Radar::isRaidable($prey)) {
                    throw new GameException(t('Überfallen kannst du nur Flotten mit Ladung, Abholflotten und Handelsflotten auf dem Rückweg von einem Transport.'));
                }
            } else {
                throw new GameException(t('Ungültiger Auftrag.'));
            }
            if (!$this->mayAttack($owner, $prey->getOwner())) {
                throw new GameException(t('Eigene und verbündete Flotten (Allianz, Bündnis, Nichtangriffspakt) greifst du nicht an.'));
            }
            if (!$this->radar->sees($owner, $prey)) {
                throw new GameException(t('Diese Flotte ist nicht auf deinem Radar.'));
            }

            [$g, $s, $p] = Radar::nextStop($prey);
            $meetAt = $prey->getNextEventAt()->modify('-1 second');
            $go = function (?Planet $base, \DateTimeImmutable $now) use ($fleet, $prey, $mission, $g, $s, $p, $meetAt, $speedPercent): void {
                $this->travel($fleet, $base, $mission, $g, $s, $p, new Resources(), $now, $prey, $meetAt, $speedPercent);
                // Wait unseen at the meeting point until just before the prey arrives.
                $fleet->delayArrivalUntil($meetAt);
            };
            $base = $this->ownPlanetAt($fleet);
            if (null !== $base) {
                $this->planets->transaction($base, fn (Planet $base, \DateTimeImmutable $now) => $go($base, $now));
            } else {
                $go(null, $this->now());
                $this->em->flush();
            }
            $result = t('{mission}: {label} fängt die Flotte von {player} bei [{g}:{s}:{p}] ab.', ['{mission}' => t($mission->label()), '{label}' => $fleet->getLabel(), '{player}' => $prey->getOwner()->getUsername(), '{g}' => $g, '{s}' => $s, '{p}' => $p]);
        });

        return $result;
    }

    /**
     * Places where a fusionator of the player stands, as sources for hovering:
     * hangars of own planets and own waiting fleets.
     *
     * @return array<string, array{label: \Symfony\Contracts\Translation\TranslatableInterface, galaxy: int, system: int, position: int}> key "planet:ID" / "fleet:ID"
     */
    public function hoverSources(User $player): array
    {
        $sources = [];
        foreach ($player->getPlanets() as $planet) {
            if ($planet->getShipCount(ShipType::Fusionator) > 0) {
                $sources['planet:'.$planet->getId()] = ['label' => t('Hangar {name} [{coords}]', ['{name}' => $planet->getName(), '{coords}' => $planet->getCoordinates()]), 'galaxy' => $planet->getGalaxy(), 'system' => $planet->getSystem(), 'position' => $planet->getPosition()];
            }
        }
        foreach ($this->fleetRepository->findBy(['owner' => $player, 'mission' => null]) as $fleet) {
            if ($fleet->getShipCount(ShipType::Fusionator) > 0) {
                $sources['fleet:'.$fleet->getId()] = ['label' => t('Flotte {label} [{coords}]', ['{label}' => $fleet->getLabel(), '{coords}' => $fleet->getCoordinates()]), 'galaxy' => $fleet->getGalaxy(), 'system' => $fleet->getSystem(), 'position' => $fleet->getPosition()];
            }
        }

        return $sources;
    }

    /** How long a fleet would hover with a fusionator from the given source. */
    public function hoverSeconds(Fleet $fleet, array $source): int
    {
        $distance = $this->navigation->distance($source['galaxy'], $source['system'], $source['position'], $fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition());

        return $this->navigation->flightSeconds($distance, $this->economy->shipSpeed(ShipType::Fusionator, $fleet->getOwner()));
    }

    /**
     * "Schweben": the fleet takes off and lands on the same spot, invisible in between.
     * It hovers as long as the chosen fusionator would need to reach it. No hydrogen,
     * only food and water for the crews.
     */
    public function hover(Fleet $fleet, User $actor, string $sourceKey): TranslatableInterface
    {
        $result = '';
        $this->withEventLock(function () use ($fleet, $actor, $sourceKey, &$result): void {
            $this->em->refresh($fleet);
            $this->assertOwner($fleet, $actor);
            if (!$fleet->isIdle() || !$fleet->isNamed()) {
                throw new GameException(t('Nur wartende Flotten können schweben.'));
            }
            $this->assertReadyToLeave($fleet);
            $source = $this->hoverSources($actor)[$sourceKey] ?? throw new GameException(t('Dort steht kein Fusionator von dir.'));
            $seconds = $this->hoverSeconds($fleet, $source);
            $supplies = $this->navigation->supplies($fleet->getShips(), $seconds, oneWay: true);

            $go = function (?Planet $base, \DateTimeImmutable $now) use ($fleet, $seconds, $supplies): void {
                $payer = null !== $base ? $base->getResources() : $fleet->getCargo();
                if (!$payer->covers($supplies)) {
                    throw new GameException(t('Für das Schweben fehlt Proviant: {food} Nahrung und {water} Wasser.', ['{food}' => new Num($supplies->food), '{water}' => new Num($supplies->water)]));
                }
                null !== $base ? $base->setResources($payer->subtract($supplies)) : $fleet->setCargo($payer->subtract($supplies));
                $fleet->depart(FleetMission::Hover, $fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition(), $now, $seconds);
            };
            $base = $this->ownPlanetAt($fleet);
            if (null !== $base) {
                $this->planets->transaction($base, fn (Planet $base, \DateTimeImmutable $now) => $go($base, $now));
            } else {
                $go(null, $this->now());
                $this->em->flush();
            }
            $result = t('{label} schwebt {value} lang unsichtbar über [{coords}].', ['{label}' => $fleet->getLabel(), '{value}' => GameExtension::formatDuration($seconds), '{coords}' => $fleet->getCoordinates()]);
        });

        return $result;
    }

    /** Turns a moving fleet around; the way back takes as long as the flight so far. */
    public function recall(Fleet $fleet, User $actor): void
    {
        $this->withEventLock(function () use ($fleet, $actor): void {
            $this->em->refresh($fleet);
            $this->assertControl($fleet, $actor);
            $now = $this->now();
            if (FleetMission::Hover === $fleet->getMission()) {
                $fleet->land();
                $this->em->flush();

                return;
            }
            if ($fleet->isIdle() || $fleet->isReturning() || $fleet->getArriveAt() <= $now) {
                throw new GameException(t('Die Flotte ist nicht auf dem Hinflug.'));
            }
            // Kommunikationstechnik: only shortly after the start the fleet still hears the recall.
            $window = Economy::recallWindow($fleet->getOwner());
            if ($now->getTimestamp() - $fleet->getDepartAt()->getTimestamp() > $window) {
                throw new GameException(0 === $window
                    ? 'Ohne Kommunikationstechnik lässt sich eine gestartete Flotte nicht zurückrufen.'
                    : t('Zu spät: Mit deiner Kommunikationstechnik lässt sich eine Flotte nur in den ersten {value} nach dem Start zurückrufen.', ['{value}' => GameExtension::formatDuration($window)]));
            }

            $fleet->recall($now);
            $this->em->flush();
        });
    }

    // ------------------------------------------------------------------
    // Event processing
    // ------------------------------------------------------------------

    /** Resolves every arrival and return that is due. */
    public function processDue(): void
    {
        $this->withEventLock(function (): void {
            foreach ($this->fleetRepository->findDue($this->now()) as $fleet) {
                // An earlier event of this run may have destroyed the fleet or turned it around.
                if (!$this->em->contains($fleet) || $fleet->isIdle() || $fleet->getNextEventAt() > $this->now()) {
                    continue;
                }
                $fleet->isReturning() ? $this->land($fleet) : $this->arrive($fleet);
                $this->em->flush();
            }
            $this->sendOverstayersHome();
            $this->giveUpAbandonedPlanets();
            $this->accountDeletion?->deleteDue();
            $this->contracts?->settleDue();
            $this->bounties?->settleDue();
            // Once a day: ships and resources of the whole game for the status page history.
            $this->stats?->recordDailySnapshot();
            $this->transportRequests?->reopenOrphans();
            $this->transportRequests?->purgeClosed();
            // The trade guild: posts and offer in place, transport requests nobody took delivered.
            $this->tradeGuild?->tick();
            // Trade posts stand on planets: gone with a lost planet; old ones on free slots are dissolved.
            $this->trade->closeLost();
            $this->trade->dissolveOldPosts();
            $this->em->flush();
        });
    }

    /** How long a fleet may wait at a trade post or on a planet without a bond. */
    public const string MAX_STAY = '24 hours';
    /** How often fleets that may stay are checked again. */
    public const string STAY_RECHECK = '1 hour';

    /**
     * Fleets waiting 24 hours at a trade post fly home for free. Fleets on the planet of a
     * player without a bond (not own, not the own alliance) fly home after 24 hours as well,
     * paying fuel and provisions from their cargo – without them they lose 10 % of their
     * ships a day and are gone after 10 days.
     */
    private function sendOverstayersHome(): void
    {
        $now = $this->now();
        $cutoff = $now->modify('-'.self::MAX_STAY);
        foreach ($this->fleetRepository->findParkedBefore($cutoff, $now->modify('-'.self::STAY_RECHECK)) as $fleet) {
            if (!$this->em->contains($fleet) || !$fleet->isIdle() || $fleet->getOwner()->isOnVacation($now)) {
                continue; // on vacation the stay limits rest as well
            }
            [$g, $s, $p] = [$fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition()];
            $post = $this->tradePosts->findAt($g, $s, $p);
            $planet = null === $post ? $this->planetRepository->findAt($g, $s, $p) : null;
            $owner = $fleet->getOwner();
            $limited = null !== $post
                || (null !== $planet && !($this->diplomacy?->mayStay($owner, $planet->getOwner()) ?? ($owner->isSameAs($planet->getOwner()) || $owner->isAllyOf($planet->getOwner())))
                    && !($this->contracts?->protects($owner, $planet) ?? false));

            // Allowed to stay (own planet, alliance, bond, contract, free slot): look again in an hour.
            if (!$limited) {
                $fleet->allowStay(true, $now);
                $fleet->unstrand();
                continue;
            }
            // Just lost the permission (left the alliance, bond ended, planet changed hands): the 24 hours start now.
            if ($fleet->isStayAllowed() || null === $fleet->getParkedAt()) {
                $wasAllowed = $fleet->isStayAllowed();
                $fleet->allowStay(false, $now);
                if ($wasAllowed && null !== $planet) {
                    $this->inbox->send($owner, t('{label} muss heim', ['{label}' => $fleet->getLabel()]), t('{label} darf nicht mehr auf {name} [{coords}] von {player} bleiben – ihr seid nicht mehr in einer Allianz und habt kein Bündnis. Sie fliegt in 24 Stunden von selbst heim, wenn du sie nicht vorher wegschickst.', ['{label}' => $fleet->getLabel(), '{name}' => $planet->getName(), '{coords}' => $planet->getCoordinates(), '{player}' => $planet->getOwner()->getUsername()]));
                }
                continue;
            }
            if ($fleet->getParkedAt() > $cutoff && null === $fleet->getStrandedSince()) {
                continue;
            }

            $where = null !== $post ? t('am Handelsstützpunkt {name} [{coords}]', ['{name}' => $post->getName(), '{coords}' => $post->getCoordinates()]) : t('auf {name} [{coords}] von {player}', ['{name}' => $planet->getName(), '{coords}' => $planet->getCoordinates(), '{player}' => $planet->getOwner()->getUsername()]);
            $home = $this->homeFor($fleet);
            if (null === $home) {
                $fleet->park($now);
                continue;
            }
            try {
                if ($fleet->hasShips()) {
                    $mission = $fleet->getCargo()->isEmpty() ? FleetMission::Station : FleetMission::TransportStation;
                    $this->launch($fleet, null, $mission, $home->getGalaxy(), $home->getSystem(), $home->getPosition(), new Resources(), $now);
                    $arrival = t('Ankunft {time}', ['{time}' => $this->localTime($fleet->getArriveAt())]);
                } else {
                    $fleet->relocate($home->getGalaxy(), $home->getSystem(), $home->getPosition());
                    $fleet->park($now);
                    $arrival = t('sofort, sie ist leer');
                }
                $this->inbox->send($owner, t('{label} fliegt heim', ['{label}' => $fleet->getLabel()]), t('{label} hat {where} gewartet und fliegt nach 24 Stunden zurück nach {name} [{coords}] ({arrival}){value}.', ['{label}' => $fleet->getLabel(), '{where}' => $where, '{name}' => $home->getName(), '{coords}' => $home->getCoordinates(), '{arrival}' => $arrival, '{value}' => null !== $post ? t(', der Handelsstützpunkt zahlt den Flug') : '']));
            } catch (GameException $e) {
                $first = null === $fleet->getStrandedSince();
                $lost = $fleet->strand($fleet->getParkedAt()->modify('+'.self::MAX_STAY), $now);
                if ($first) {
                    $this->inbox->send($owner, t('{label} sitzt fest', ['{label}' => $fleet->getLabel()]), t('{label} steht seit 24 Stunden {where} – ohne Bündnis darf sie dort nicht bleiben, hat aber nicht genug für den Heimflug: {message} Jeden Tag gehen 10 % der Schiffe verloren, nach 10 Tagen ist die Flotte zerstört.', ['{label}' => $fleet->getLabel(), '{where}' => $where, '{message}' => $e->getMessage()]));
                }
                if (!$fleet->hasShips()) {
                    $this->inbox->send($owner, t('{label} ist verloren', ['{label}' => $fleet->getLabel()]), t('{label} ist {where} zugrunde gegangen.', ['{label}' => $fleet->getLabel(), '{where}' => $where]));
                    $this->fleetRepository->forget($fleet);
                } elseif ($lost > 0) {
                    $this->inbox->send($owner, t('{label} verliert Schiffe', ['{label}' => $fleet->getLabel()]), t('{label} hat {where} {lost} Schiff(e) verloren. Übrig: {value}.', ['{label}' => $fleet->getLabel(), '{where}' => $where, '{lost}' => $lost, '{value}' => Goods::ships($fleet->getShipList())]));
                }
            }
        }
    }

    /** Planets whose 48 hours of abandoning are over disappear; fleets there stay at the free slot. */
    private function giveUpAbandonedPlanets(): void
    {
        foreach ($this->planetRepository->findAbandonedBefore($this->now()) as $planet) {
            $owner = $planet->getOwner();
            [$name, $where] = [$planet->getName(), $planet->getCoordinates()];
            if ($this->planets->giveUp($planet)) {
                $this->inbox->send($owner, t('{name} verlassen', ['{name}' => $name]), t('Du hast {name} [{where}] verlassen. Gebäude, Rohstoffe und die Schiffe im Hangar sind verloren; Flotten, die dort standen, warten jetzt am freien Platz.', ['{name}' => $name, '{where}' => $where]));
            }
        }
    }

    /** Where an overstaying fleet flies: the own planet it came from, else the home world. */
    private function homeFor(Fleet $fleet): ?Planet
    {
        $owner = $fleet->getOwner();
        if (null !== $home = $fleet->getHome()) {
            $planet = $this->planetRepository->findAt(...$home);
            if (null !== $planet && $planet->getOwner()->isSameAs($owner)) {
                return $planet;
            }
        }
        $planets = $owner->getPlanets();

        return $planets->findFirst(static fn (int $k, Planet $p): bool => $p->isHomeworld()) ?? ($planets->first() ?: null);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Validates the mission and puts the fleet on its way. $base (an own planet
     * at the fleet's position) supplies cargo, fuel and provisions; without a
     * base the fleet pays fuel and provisions from its own cargo.
     */
    /** @param array<string, float> $order */
    private function launch(Fleet $fleet, ?Planet $base, FleetMission $mission, int $galaxy, int $system, int $position, Resources $cargo, \DateTimeImmutable $now, array $order = [], float $speedPercent = 100.0): void
    {
        if (FleetMission::Gather === $mission) {
            throw new GameException(t('Ungültiger Auftrag.'));
        }
        if (!$this->colonizer->isValidSlot($galaxy, $system, $position)) {
            throw new GameException(t('Diese Koordinaten gibt es nicht.'));
        }
        $target = $this->planetRepository->findAt($galaxy, $system, $position);
        $atPlanet = $this->planetRepository->findAt($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition());
        // A fleet can fuse the planet it is waiting at; every other mission needs a different target.
        $sameSpot = $fleet->isAt($galaxy, $system, $position) || (null !== $target && $target->getId() === $atPlanet?->getId());
        if ($sameSpot && FleetMission::Fuse !== $mission) {
            throw new GameException(t('Die Flotte befindet sich bereits an diesem Ziel.'));
        }

        $owner = $fleet->getOwner();
        $ships = $fleet->getShips();
        $post = $this->tradePosts->findAt($galaxy, $system, $position);
        // Transport or station to someone else's trade post (the guild's or one on a trader's
        // planet): the fleet flies there and waits to trade – it is no delivery to that planet.
        if (null !== $post && (null === $target || !$target->getOwner()->isSameAs($owner)) && \in_array($mission, [FleetMission::Transport, FleetMission::TransportStation, FleetMission::Station], true)) {
            $mission = FleetMission::Trade;
        }
        $order = array_filter(array_map(static fn (mixed $v): float => max(0, floor((float) $v)), array_intersect_key($order, array_flip(TradeDeal::goods()))));
        $this->validateMission($mission, $owner, $target, $ships, $fleet->isNamed(), $post, $order);
        $this->assertNotNewbie($mission, $fleet, $target);
        // Pushing protection: resources for another player without payment are limited
        // (a delivery for a paid transport request is no gift).
        if (\in_array($mission, [FleetMission::Transport, FleetMission::TransportStation], true) && null !== $target
            && !$target->getOwner()->isSameAs($owner) && true !== $this->transportRequests?->isPaidDelivery($fleet)) {
            $this->pushing?->checkAndRecord($owner, $target->getOwner(), $fleet->getCargo()->add($cargo)->total(), $now);
        }
        // Cargo travels openly: a fleet with cargo has to transport, it can't be stationed.
        if (FleetMission::Station === $mission && !$fleet->getCargo()->add($cargo)->isEmpty()) {
            throw new GameException(t('Eine Flotte mit Ladung kann nicht stationieren – schicke sie mit „Transport“ (dabei ist sie auf dem Radar sichtbar) oder lade die Ladung vorher ab.'));
        }

        foreach (ResourceType::cases() as $type) {
            if ($cargo->get($type) < 0) {
                throw new GameException(t('Die Ladung darf nicht negativ sein.'));
            }
        }
        if (null === $base && !$cargo->isEmpty()) {
            throw new GameException(t('Ladung kann nur an einem eigenen Planeten aufgenommen werden.'));
        }
        $capacity = $this->navigation->cargoCapacity($ships);
        if (0 === $capacity && !$cargo->isEmpty()) {
            throw new GameException(t('Nur Handelsschiffe (Frachter, Großfrachter) können Fracht transportieren. Nimm einen Frachter in die Flotte auf.'));
        }
        if ($fleet->getCargo()->add($cargo)->total() > $capacity) {
            throw new GameException(t('Die Ladung übersteigt die Frachtkapazität von {capacity}.', ['{capacity}' => new Num($capacity)]));
        }

        // Leaving an own planet: remember it – trade fleets and fleets sent home after
        // 24 hours abroad fly back there.
        if (null !== $base) {
            $fleet->rememberHome($base->getGalaxy(), $base->getSystem(), $base->getPosition());
        }
        $this->travel($fleet, $base, $mission, $galaxy, $system, $position, $cargo, $now, speedPercent: $speedPercent);
        if (FleetMission::Trade === $mission || FleetMission::Pickup === $mission) {
            $fleet->setTradeOrder($order);
        }
    }

    /**
     * Pays fuel and provisions for the round trip (from the base planet, or from the
     * fleet's cargo without a base), loads the cargo and puts the fleet on its way.
     *
     * @return int one-way flight time in seconds
     */
    private function travel(Fleet $fleet, ?Planet $base, FleetMission $mission, int $galaxy, int $system, int $position, Resources $cargo, \DateTimeImmutable $now, ?Fleet $targetFleet = null, ?\DateTimeImmutable $mustArriveBefore = null, float $speedPercent = 100.0): int
    {
        $ships = $fleet->getShips();
        $distance = $this->navigation->distance($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition(), $galaxy, $system, $position);
        $seconds = $this->navigation->flightSeconds($distance, $this->navigation->slowestSpeed($ships, $fleet->getOwner()), $speedPercent);
        if (null !== $mustArriveBefore && $now->modify("+{$seconds} seconds") >= $mustArriveBefore) {
            throw new GameException(t('Zu langsam: deine Flotte bräuchte {value}, das Ziel ist in {value2} dort.', ['{value}' => GameExtension::formatDuration($seconds), '{value2}' => GameExtension::formatDuration($mustArriveBefore->getTimestamp() - $now->getTimestamp())]));
        }
        // Fleets that stay at the target (and probes, which burn up) pay for one way only.
        $oneWay = $mission->isOneWay($fleet->isNamed());
        $fuel = $this->navigation->fuel($ships, $distance, $oneWay, $speedPercent);
        $supplies = $this->navigation->supplies($ships, $seconds, $oneWay);
        $travel = $supplies->add(new Resources(hydrogen: $fuel));
        $missing = t('Treibstoff ({fuel} Wasserstoff) und Proviant ({food} Nahrung, {water} Wasser)', ['{fuel}' => new Num($fuel), '{food}' => new Num($supplies->food), '{water}' => new Num($supplies->water)]);

        // Leaving a trade post costs nothing: the post fuels and provisions departing fleets.
        if (null === $base && null !== $this->tradePosts->findAt($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition())) {
            $travel = new Resources();
        }

        if (null !== $base) {
            $needed = $cargo->add($travel);
            if (!$base->getResources()->covers($needed)) {
                throw new GameException(t('Nicht genügend Rohstoffe für Ladung, {missing}.', ['{missing}' => $missing]));
            }
            $base->setResources($base->getResources()->subtract($needed));
            $fleet->setCargo($fleet->getCargo()->add($cargo));
        } else {
            if (!$fleet->getCargo()->covers($travel)) {
                throw new GameException(t('Die Ladung der Flotte reicht nicht für {missing}.', ['{missing}' => $missing]));
            }
            $fleet->setCargo($fleet->getCargo()->subtract($travel));
        }

        $fleet->depart($mission, $galaxy, $system, $position, $now, $seconds, $targetFleet);

        return $seconds;
    }

    /**
     * @param array<string, int> $ships
     *
     * @return array<string, int> the ships taken
     */
    private function takeFromHangar(Planet $planet, array $ships): array
    {
        $valid = [];
        foreach ($ships as $type => $count) {
            $shipType = ShipType::tryFrom((string) $type);
            $count = (int) $count;
            if (null === $shipType || $count <= 0) {
                continue;
            }
            if ($shipType->isStationary()) {
                throw new GameException(t('{shipType} steht fest auf dem Planeten und kann nicht in eine Flotte.', ['{shipType}' => t($shipType->label())]));
            }
            if ($shipType->isProbe()) {
                throw new GameException(t('{shipType} können nicht in Flotten aufgenommen werden. Sie starten über 🔭 direkt aus dem Hangar.', ['{shipType}' => t($shipType->label())]));
            }
            if ($count > $planet->getShipCount($shipType)) {
                throw new GameException(t('Im Hangar stehen nur {value} × {shipType}.', ['{value}' => $planet->getShipCount($shipType), '{shipType}' => t($shipType->label())]));
            }
            $valid[$shipType->value] = $count;
        }

        if ([] === $valid) {
            throw new GameException(t('Wähle mindestens ein Schiff aus.'));
        }
        foreach ($valid as $type => $count) {
            $planet->addShips(ShipType::from($type), -$count);
        }

        return $valid;
    }

    /**
     * @param array<string, int>   $ships
     * @param array<string, float> $order
     */
    private function validateMission(FleetMission $mission, User $player, ?Planet $target, array $ships, bool $named, ?TradePost $post = null, array $order = []): void
    {
        $owned = null !== $target && $target->getOwner()->getId() === $player->getId();

        // Only trade fleets dock at trade posts: freighters and large freighters.
        if (null !== $post && \in_array($mission, [FleetMission::Trade, FleetMission::Supply, FleetMission::Station], true)) {
            foreach ($ships as $type => $count) {
                $shipType = ShipType::from($type);
                if ($count > 0 && !$shipType->carriesCargo()) {
                    throw new GameException(t('Zu Handelsstützpunkten dürfen nur Handelsflotten (Frachter, Großfrachter). {shipType} gehört nicht dazu. Fusionatoren verkaufst du auf dem Fusionator-Markt.', ['{shipType}' => t($shipType->label())]));
                }
            }
        }

        $error = match ($mission) {
            FleetMission::Transport, FleetMission::TransportStation => null === $target ? 'Am Ziel befindet sich kein Planet.' : null,
            // Named fleets may be parked anywhere; quick dispatches only join the hangar of an own planet.
            FleetMission::Station => $owned || $named ? null : 'Ohne Flottennamen kann nur auf eigenen Planeten stationiert werden.',
            FleetMission::Jump => match (true) {
                !$named => 'Springen können nur benannte Flotten.',
                null === $target => 'Springen kannst du nur zu einem besiedelten Planeten.',
                default => null,
            },
            FleetMission::Colonize => match (true) {
                null !== $target => 'Dieser Platz ist bereits besiedelt.',
                null !== $post => 'Auf diesem Platz steht ein Handelsstützpunkt.',
                ($ships[ShipType::ColonyShip->value] ?? 0) < 1 => 'Zum Kolonisieren wird ein Kolonieschiff benötigt.',
                Economy::ownedSlots($player) + $this->fleetRepository->countColonyShipsUnderway($player) >= $this->economy->colonySlots($player)
                    => t('Deine Kolonisationstechnik erlaubt {value} Planetenplätze (verschweißte zählen einzeln) – sie sind belegt oder schon von Kolonieschiffen angeflogen. Erforsche „Kolonisationstechnik“ weiter.', ['{value}' => $this->economy->colonySlots($player)]),
                default => null,
            },
            FleetMission::Fuse => match (true) {
                !$owned => 'Fusionieren ist nur auf deinen eigenen Planeten möglich.',
                $target->isIntegrating($this->now()) => t('{name} geht erst am {value} in deinen Besitz über.', ['{name}' => $target->getName(), '{value}' => new When($target->getIntegratedAt())]),
                ($ships[ShipType::Fusionator->value] ?? 0) < 1 => 'Zum Fusionieren wird ein Fusionator benötigt.',
                // Checked already now: planet above, owner, and whether both together are a legal fusion.
                default => $this->fusion->plan($target)['reason'],
            },
            FleetMission::Spy => match (true) {
                $owned => 'Deine eigenen Planeten musst du nicht ausspionieren.',
                ($ships[ShipType::Scout->value] ?? 0) < 1 => 'Zum Spionieren wird mindestens eine Sonde benötigt.',
                default => null,
            },
            FleetMission::Pickup => match (true) {
                !$owned => 'Rohstoffe abholen kannst du nur von deinen eigenen Planeten.',
                0 === $this->navigation->cargoCapacity($ships) => 'Rohstoffe abholen können nur Handelsschiffe (Frachter, Großfrachter).',
                [] === array_filter(array_intersect_key($order, array_flip(array_map(static fn (ResourceType $t): string => $t->value, ResourceType::cases())))) => 'Gib an, wie viel von welchem Rohstoff abgeholt werden soll.',
                default => null,
            },
            FleetMission::Supply => match (true) {
                null === $post => 'Dort steht kein Handelsstützpunkt.',
                !$post->getOwner()->isSameAs($player) => 'Nur der Besitzer kann seinen Handelsstützpunkt beliefern.',
                !$post->isActive() => 'Dieser Stützpunkt ist aufgegeben. Du kannst nur noch die Ware abholen.',
                default => null,
            },
            FleetMission::Trade => match (true) {
                null === $post => 'Dort steht kein Handelsstützpunkt.',
                !$post->isAccessibleTo($player) => 'Dieser Handelsstützpunkt ist für dich gesperrt.',
                !$named => 'Zum Handeln braucht die Flotte einen Namen.',
                default => null,
            },
            FleetMission::Attack => match (true) {
                null !== $this->attackBans?->refusal() => $this->attackBans->refusal(),
                // Trade posts stand on planets and are attacked as planets; only the guild's central exchanges stand alone.
                null === $target && null !== $post => t('Die Zentralbörsen der Handelsgilde können nicht angegriffen werden.'),
                null === $target => 'Dort gibt es keinen Planeten.',
                null !== self::freightersInWarfleet($ships) => self::freightersInWarfleet($ships),
                // A fused home planet can lose its lower planets, only a single one is untouchable.
                $target->isHomeworld() && !$target->isFused() && ($ships[ShipType::InvasionShip->value] ?? 0) > 0 && !$owned && !$target->getOwner()->isInactive($this->now())
                    => t('{name} [{coords}] ist der Heimatplanet von {player} und kann nicht erobert werden. Lass die Invasionsschiffe zu Hause oder wähle ein anderes Ziel.', ['{name}' => $target->getName(), '{coords}' => $target->getCoordinates(), '{player}' => $target->getOwner()->getUsername()]),
                $owned => 'Deine eigenen Planeten kannst du nicht angreifen.',
                $target->getOwner()->isSystem() && TradeGuild::NAME === $target->getOwner()->getUsername() => t('Die Zentralbörsen der Handelsgilde können nicht angegriffen werden.'),
                $player->isAllyOf($target->getOwner()) => 'Mitglieder deiner Allianz kannst du nicht angreifen.',
                null !== $this->vacationProtects($target) => $this->vacationProtects($target),
                !$this->mayAttack($player, $target->getOwner()) => t('Mit der Allianz von {player} habt ihr ein {value} – Angriffe sind ausgeschlossen.', ['{player}' => $target->getOwner()->getUsername(), '{value}' => $this->diplomacy?->kindBetween($player, $target->getOwner())?->label() ?? 'Abkommen']),
                null !== $this->lifeFormRefusal($player, $target->getOwner()) => $this->lifeFormRefusal($player, $target->getOwner()),
                default => null,
            },
            FleetMission::Salvage => ($ships[ShipType::Salvager->value] ?? 0) < 1 ? 'Zum Bergen wird ein Bergungsschiff benötigt.' : null,
            FleetMission::Gather, FleetMission::Intercept, FleetMission::Raid, FleetMission::Hover => 'Ungültiger Auftrag.',
        };

        if (null !== $error) {
            throw new GameException($error);
        }
    }

    private function arrive(Fleet $fleet): void
    {
        $target = $this->planetRepository->findAt($fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition());
        $player = $fleet->getOwner();
        $where = '['.$fleet->getTargetCoordinates().']';
        $name = $fleet->isNamed() ? t('Flotte „{name}“', ['{name}' => $fleet->getName()]) : t('Deine Flotte');

        switch ($fleet->getMission()) {
            case FleetMission::Transport:
            case FleetMission::TransportStation:
                if (null === $target) {
                    $this->inbox->send($player, t('Transport nach {where} gescheitert', ['{where}' => $where]), t('Am Ziel befand sich kein Planet mehr. {name} kehrt mit ihrer Ladung zurück.', ['{name}' => $name]));
                    break;
                }
                $cargo = $fleet->getCargo();
                $this->planets->transaction($target, static fn (Planet $t) => $t->setResources($t->getResources()->add($cargo)));
                $fleet->setCargo(new Resources());
                $this->transportRequests?->delivered($fleet, $target);
                $this->inbox->send($player, t('Transport nach {where} angekommen', ['{where}' => $where]), new Lines([t('{name} hat folgende Ladung bei {planet} {where} abgeliefert:', ['{name}' => $name, '{planet}' => $target->getName(), '{where}' => $where]), $this->formatResources($cargo)]));
                if ($target->getOwner()->getId() !== $player->getId()) {
                    $this->inbox->send($target->getOwner(), t('Lieferung von {player}', ['{player}' => $player->getUsername()]), new Lines([t('{player} hat dir auf {planet} {where} Rohstoffe geliefert:', ['{player}' => $player->getUsername(), '{planet}' => $target->getName(), '{where}' => $where]), $this->formatResources($cargo)]));
                }
                // "Transportieren und stationieren": the fleet stays there instead of flying back.
                if (FleetMission::TransportStation === $fleet->getMission()) {
                    if ($fleet->isNamed()) {
                        $fleet->settleAtTarget();
                        $this->inbox->send($player, t('{name} auf {where} stationiert', ['{name}' => $name, '{where}' => $where]), t('{name} bleibt bei {name2} {where} und wartet dort auf neue Befehle.', ['{name}' => $name, '{name2}' => $target->getName(), '{where}' => $where]));

                        return;
                    }
                    if ($target->getOwner()->isSameAs($player)) {
                        $this->planets->transaction($target, fn (Planet $t) => $this->unload($t, $fleet));
                        $this->fleetRepository->forget($fleet);

                        return;
                    }
                }
                break;

            case FleetMission::Jump:
                // Cargo stays on board; the fleet waits at the planet for new orders.
                $fleet->settleAtTarget();
                $this->inbox->send($player, t('{name} nach {where} gesprungen', ['{name}' => $name, '{where}' => $where]), t('{name} ist bei {value} {where} eingetroffen und wartet dort auf neue Befehle.', ['{name}' => $name, '{value}' => $target?->getName() ?? t('dem Planeten'), '{where}' => $where]));

                return;

            case FleetMission::Station:
                // The planet changed hands while the ships were underway (e.g. invaded): they land
                // anyway and wait there – without permission to stay they have to leave within 24
                // hours, and a station flight only paid the way out.
                if (!$fleet->isNamed() && null !== $target && !$this->mayStayAt($player, $target)) {
                    $fleet->settleAtTarget();
                    $this->inbox->send($player, t('{name} bei {player} gelandet', ['{name}' => $name, '{player}' => $target->getOwner()->getUsername()]), t('{name} [{coords}] gehört inzwischen {player}. {fleet} ist trotzdem gelandet und wartet dort – ohne Allianz oder Bündnis darf sie höchstens 24 Stunden bleiben. Für den Heimflug braucht sie Treibstoff und Proviant aus ihrer Ladung; reicht das nicht, sitzt sie fest und verliert jeden Tag 10 % ihrer Schiffe.', ['{name}' => $target->getName(), '{coords}' => $target->getCoordinates(), '{player}' => $target->getOwner()->getUsername(), '{fleet}' => $name]));

                    return;
                }
                if ($fleet->isNamed()) {
                    $fleet->settleAtTarget();
                    // At an own planet the cargo goes into its storage.
                    $cargo = $fleet->getCargo();
                    $delivered = null;
                    if (!$cargo->isEmpty() && null !== $target && $target->getOwner()->isSameAs($player)) {
                        $this->planets->transaction($target, static fn (Planet $t) => $t->setResources($t->getResources()->add($cargo)));
                        $fleet->setCargo(new Resources());
                        $delivered = new Lines([t("\nDie Ladung wurde ins Lager von {planet} gebracht:", ['{planet}' => $target->getName()]), $this->formatResources($cargo)]);
                    }
                    $limit = null !== $target && !$this->mayStayAt($player, $target)
                        ? t("\nAchtung: Ohne Allianz oder Bündnis mit {player} darf sie dort höchstens 24 Stunden bleiben. Für den Heimflug braucht sie Treibstoff und Proviant aus ihrer Ladung; reicht das nicht, sitzt sie fest und verliert jeden Tag 10 % ihrer Schiffe.", ['{player}' => $target->getOwner()->getUsername()])
                        : null;
                    $this->inbox->send($player, t('{name} auf {where} stationiert', ['{name}' => $name, '{where}' => $where]), new Lines([t('{name} ist eingetroffen und wartet dort auf neue Befehle.', ['{name}' => $name]), $delivered, $limit]));

                    return;
                }
                if (null === $target || $target->getOwner()->getId() !== $player->getId()) {
                    $this->inbox->send($player, t('Stationierung auf {where} gescheitert', ['{where}' => $where]), t('Der Planet gehört dir nicht mehr. Die Flotte kehrt zurück.'));
                    break;
                }
                $this->planets->transaction($target, fn (Planet $t) => $this->unload($t, $fleet));
                $this->fleetRepository->forget($fleet);
                $this->inbox->send($player, t('Flotte auf {where} stationiert', ['{where}' => $where]), t('Deine Schiffe sind auf {name} {where} eingetroffen und im Hangar stationiert.', ['{name}' => $target->getName(), '{where}' => $where]));

                return;

            case FleetMission::Pickup:
                if (null === $target || !$target->getOwner()->isSameAs($player)) {
                    $this->inbox->send($player, t('Abholung von {where} gescheitert', ['{where}' => $where]), t('Der Planet gehört dir nicht mehr. {name} kehrt zurück.', ['{name}' => $name]));
                    break;
                }
                $report = '';
                $this->planets->transaction($target, function (Planet $t) use ($fleet, &$report): void {
                    $report = $this->pickUp($t, $fleet);
                });
                $this->inbox->send($player, t('Rohstoffe von {name} abgeholt', ['{name}' => $target->getName()]), t("{name} hat auf {name2} {where} geladen:\n{report}\nSie bringt die Ladung zurück zu ihrem Startplaneten.", ['{name}' => $name, '{name2}' => $target->getName(), '{where}' => $where, '{report}' => $report]));
                break;

            case FleetMission::Supply:
                $post = $this->tradePosts->findAt($fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition());
                if (null === $post || !$post->getOwner()->isSameAs($player) || !$post->isActive()) {
                    $this->inbox->send($player, t('Belieferung von {where} gescheitert', ['{where}' => $where]), t('Dort steht kein aktiver Handelsstützpunkt von dir mehr. {name} kehrt zurück.', ['{name}' => $name]));
                    break;
                }
                $delivered = $this->trade->supply($post, $fleet);
                $this->inbox->send($player, t('{name} beliefert', ['{name}' => $post->getName()]), t('{name} hat an {name2} {where} abgeliefert: {delivered}.', ['{name}' => $name, '{name2}' => $post->getName(), '{where}' => $where, '{delivered}' => $delivered]));
                if (!$fleet->hasShips()) {
                    $this->fleetRepository->forget($fleet);

                    return;
                }
                break;

            case FleetMission::Trade:
                $post = $this->tradePosts->findAt($fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition());
                if (null === $post || !$post->isAccessibleTo($player)) {
                    $this->inbox->send($player, t('Handel an {where} gescheitert', ['{where}' => $where]), t('Der Handelsstützpunkt ist nicht mehr da oder für dich gesperrt. {name} kehrt zurück.', ['{name}' => $name]));
                    break;
                }
                // The fleet waits at the post; buying and selling happen on the trade post page.
                $fleet->settleAtTarget();
                $this->inbox->send($player, t('{name} am Handelsstützpunkt {name2} {where}', ['{name}' => $name, '{name2}' => $post->getName(), '{where}' => $where]), t('{name} ist bei {name2} {where} eingetroffen und wartet dort. Kaufen und verkaufen kannst du auf der Seite des Handelsstützpunkts (im Universum anklicken).', ['{name}' => $name, '{name2}' => $post->getName(), '{where}' => $where]));

                return;

            case FleetMission::Colonize:
                if (null !== $this->tradePosts->findAt($fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition())) {
                    $this->inbox->send($player, t('Kolonisierung von {where} gescheitert', ['{where}' => $where]), t('Auf dem Platz steht inzwischen ein Handelsstützpunkt. {name} kehrt zurück.', ['{name}' => $name]));
                    break;
                }
                if (null !== $target) {
                    $this->inbox->send($player, t('Kolonisierung von {where} gescheitert', ['{where}' => $where]), t('Der Platz wurde inzwischen besiedelt. {name} kehrt zurück.', ['{name}' => $name]));
                    break;
                }
                if (Economy::ownedSlots($player) >= $this->economy->colonySlots($player)) {
                    $this->inbox->send($player, t('Kolonisierung von {where} gescheitert', ['{where}' => $where]), t('Deine Kolonisationstechnik erlaubt nur {value} Planetenplätze. {name} kehrt zurück.', ['{value}' => $this->economy->colonySlots($player), '{name}' => $name]));
                    break;
                }
                $settlers = ShipType::ColonyShip->spec()->crew;
                $colony = $this->colonizer->foundColony($player, $fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition(), $settlers, $fleet->getCargo());
                $fleet->removeShips(ShipType::ColonyShip, 1);
                $fleet->setCargo(new Resources());
                $this->inbox->send($player, t('Neue Kolonie {name} auf {where}', ['{name}' => $colony->getName(), '{where}' => $where]), t('Dein Kolonieschiff ist auf {where} gelandet. {settlers} Siedler haben dort die Kolonie {name} gegründet und die Ladung ausgeladen. Umbenennen kannst du sie in der Übersicht.', ['{where}' => $where, '{settlers}' => $settlers, '{name}' => $colony->getName()]));
                if (!$fleet->hasShips()) {
                    $this->fleetRepository->forget($fleet);

                    return;
                }
                break;

            case FleetMission::Fuse:
                if (null === $target || $target->getOwner()->getId() !== $player->getId()) {
                    $this->inbox->send($player, t('Fusion auf {where} gescheitert', ['{where}' => $where]), t('Der Planet gehört dir nicht mehr. Der Fusionator kehrt zurück.'));
                    break;
                }
                $sent = $fleet->getShipCount(ShipType::Fusionator);
                try {
                    $used = $this->fusion->fuseOnArrival($target, $sent);
                } catch (GameException $e) {
                    $this->inbox->send($player, t('Fusion auf {where} gescheitert', ['{where}' => $where]), new Lines([$e->translatable(), t('{name} kehrt zurück.', ['{name}' => $name])]));
                    break;
                }
                $fleet->removeShips(ShipType::Fusionator, $used);
                if ($used < $sent) {
                    $this->inbox->send($player, t('Überzählige Fusionatoren von {where}', ['{where}' => $where]), t('Für die Fusion wurden nur {used} von {sent} Fusionatoren gebraucht. {name} kehrt mit dem Rest zurück.', ['{used}' => $used, '{sent}' => $sent, '{name}' => $name]));
                }
                if (!$fleet->hasShips()) {
                    $this->fleetRepository->forget($fleet);

                    return;
                }
                break;

            case FleetMission::Spy:
                [$g, $s, $p] = [$fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition()];
                if (null !== $prey = $fleet->getTargetFleet()) {
                    // Probe sent after a fleet: it must still be on its way to this place.
                    if ($prey->isMoving() && Radar::nextStop($prey) === [$g, $s, $p] && $prey->getNextEventAt() >= $fleet->getArriveAt()) {
                        $this->inbox->send($player, t('Flotte von {player} ausgespäht', ['{player}' => $prey->getOwner()->getUsername()]), $this->espionage->scoutFleet($player, $prey));
                        if (null !== $notice = $this->espionage->spiedNotice($prey->getOwner(), $player, t('Deine Flotte {name} bei {where}', ['{name}' => $prey->getLabel(), '{where}' => $where]))) {
                            $this->inbox->send($prey->getOwner(), ...$notice);
                        }
                    } else {
                        $this->inbox->send($player, t('Sonde bei {where}: Flotte verpasst', ['{where}' => $where]), t('Die Flotte war nicht mehr dorthin unterwegs. Die Sonde hat nichts entdeckt und ist verglüht.'));
                    }
                } elseif (null === $target) {
                    $this->inbox->send($player, t('Spionagebericht {where}', ['{where}' => $where]), $this->espionage->report($player, $g, $s, $p, null));
                } else {
                    $this->planets->transaction($target, fn (Planet $t) => $this->inbox->send($player, t('Spionagebericht {name} {where}', ['{name}' => $t->getName(), '{where}' => $where]), $this->espionage->report($player, $g, $s, $p, $t)));
                    if (null !== $notice = $this->espionage->spiedNotice($target->getOwner(), $player, t('Dein Planet {name} {where}', ['{name}' => $target->getName(), '{where}' => $where]))) {
                        $this->inbox->send($target->getOwner(), ...$notice);
                    }
                }

                // Probes are single-use: they burn out on arrival, whether they saw anything or not.
                $fleet->removeShips(ShipType::Scout, $fleet->getShipCount(ShipType::Scout));
                if (!$fleet->hasShips()) {
                    $this->fleetRepository->forget($fleet);

                    return;
                }
                break;

            case FleetMission::Hover:
                // Lands where it took off; no return flight.
                $fleet->land();

                return;

            case FleetMission::Attack:
                // A joiner fights in the battle of its leading fleet, which resolves it.
                $lead = $fleet->getTargetFleet();
                if (null !== $lead && $this->em->contains($lead) && FleetMission::Attack === $lead->getMission() && !$lead->isReturning()
                    && $lead->getTargetCoordinates() === $fleet->getTargetCoordinates()) {
                    return;
                }
                if (null === $target || !$this->mayAttack($player, $target->getOwner())) {
                    $this->inbox->send($player, t('Angriff auf {where} abgebrochen', ['{where}' => $where]), t('Das Ziel ist kein gegnerischer Planet mehr (eigener Planet, Allianz, Bündnis oder Nichtangriffspakt). {name} kehrt zurück.', ['{name}' => $name]));
                    break;
                }
                if (null !== $reason = $this->attackBans?->refusal() ?? $this->vacationProtects($target, $fleet->getDepartAt()) ?? NewbieProtection::refusal(FleetMission::Attack, $player, $target) ?? $this->lifeFormRefusal($player, $target->getOwner())) {
                    $this->inbox->send($player, t('Angriff auf {where} abgebrochen', ['{where}' => $where]), t('{reason} {name} kehrt zurück.', ['{reason}' => $reason, '{name}' => $name]));
                    break;
                }
                $victim = $target->getOwner();
                $joiners = [];
                foreach ($this->fleetRepository->findAttackJoiners($fleet) as $joiner) {
                    if ($joiner->getTargetCoordinates() !== $fleet->getTargetCoordinates()) {
                        continue;
                    }
                    if (!$this->mayAttack($joiner->getOwner(), $victim)) {
                        $this->inbox->send($joiner->getOwner(), t('Verbandsangriff auf {where} abgebrochen', ['{where}' => $where]), t('{label} darf {player} nicht angreifen (Allianz, Bündnis oder Nichtangriffspakt) und kehrt zurück.', ['{label}' => $joiner->getLabel(), '{player}' => $victim->getUsername()]));
                        $joiner->startReturn();
                        continue;
                    }
                    $joiners[] = $joiner;
                }
                $this->combat->attackPlanet($fleet, $target, $joiners);
                foreach ($joiners as $joiner) {
                    $joiner->hasShips() ? $joiner->startReturn() : $this->fleetRepository->forget($joiner);
                }
                if (!$fleet->hasShips()) {
                    $this->fleetRepository->forget($fleet);

                    return;
                }
                break;

            case FleetMission::Salvage:
                $this->inbox->send($player, t('Bergung bei {where}', ['{where}' => $where]), new Lines([$this->combat->salvage($fleet), t('{name} kehrt zurück.', ['{name}' => $name])], ' '));
                break;

            case FleetMission::Intercept:
            case FleetMission::Raid:
                $prey = $fleet->getTargetFleet();
                $raid = FleetMission::Raid === $fleet->getMission();
                $stillComing = null !== $prey && $prey->isMoving() && Radar::nextStop($prey) === [$fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition()]
                    // Compare with the moment of the ambush, not "now": events are resolved lazily.
                    && $prey->getNextEventAt() >= $fleet->getArriveAt() && ($raid ? $prey->getCargo()->total() >= 1 : FleetMission::Attack === $prey->getMission() && !$prey->isReturning());
                if (!$stillComing) {
                    $this->inbox->send($player, $raid ? t('Überfall bei {where} verpasst', ['{where}' => $where]) : t('Abfangen bei {where} verpasst', ['{where}' => $where]), t('Das Ziel ist nicht mehr dorthin unterwegs. {name} kehrt zurück.', ['{name}' => $name]));
                    break;
                }
                if (null !== $ban = $this->attackBans?->refusal()) {
                    $this->inbox->send($player, $raid ? t('Überfall bei {where} abgebrochen', ['{where}' => $where]) : t('Abfangen bei {where} abgebrochen', ['{where}' => $where]), t('{reason} {name} kehrt zurück.', ['{reason}' => $ban, '{name}' => $name]));
                    break;
                }
                // The whole convoy (escorts included) or joint attack is in the fight.
                $group = $this->travelGroup($prey);
                $raid ? $this->combat->raid($fleet, $prey, $group) : $this->combat->intercept($fleet, $prey, $group);
                if (!$fleet->hasShips()) {
                    $this->fleetRepository->forget($fleet);

                    return;
                }
                break;

            case FleetMission::Gather:
                $joined = $fleet->getTargetFleet();
                if (null !== $joined && $joined->isIdle() && $joined->isAt($fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition())) {
                    $joined->merge($fleet);
                    $this->fleetRepository->forget($fleet);

                    return;
                }
                $this->inbox->send($player, t('Zusammenziehen nach {where} gescheitert', ['{where}' => $where]), t('Die Zielflotte war nicht mehr dort. Die Schiffe kehren zurück.'));
                break;
        }

        $fleet->startReturn();
    }

    /** A returning fleet reaches its point of departure. */
    private function land(Fleet $fleet): void
    {
        // Picked-up resources go straight into the storage of the planet the fleet returns to.
        if (FleetMission::Pickup === $fleet->getMission() && $fleet->isNamed() && null !== $home = $this->ownPlanetAt($fleet)) {
            $this->planets->transaction($home, function (Planet $home) use ($fleet): void {
                $home->setResources($home->getResources()->add($fleet->getCargo()));
                $fleet->setCargo(new Resources());
            });
        }
        if ($fleet->isNamed()) {
            $fleet->land();

            return;
        }

        // Quick dispatches dissolve into the hangar of the own planet they started from.
        $home = $this->ownPlanetAt($fleet);
        if (null === $home) {
            $fleet->land();
            $fleet->setName('Gestrandete Flotte');
            $this->inbox->send($fleet->getOwner(), t('Flotte gestrandet'), t('Deine Schiffe sind zu [{coords}] zurückgekehrt, aber der Planet gehört dir nicht mehr. Sie warten dort als „Gestrandete Flotte“.', ['{coords}' => $fleet->getCoordinates()]));

            return;
        }
        $this->planets->transaction($home, fn (Planet $home) => $this->unload($home, $fleet));
        $this->fleetRepository->forget($fleet);
    }

    /**
     * Pickup at an own planet: cargo brought along goes into storage, then the ordered
     * amounts are loaded – at most what is in stock and fits into the hold.
     */
    private function pickUp(Planet $planet, Fleet $fleet): string
    {
        $planet->setResources($planet->getResources()->add($fleet->getCargo()));
        $fleet->setCargo(new Resources());
        $free = $this->navigation->cargoCapacity($fleet->getShips());
        $order = $fleet->getTradeOrder();
        $stock = $planet->getResources();
        $taken = Resources::fromCallback(static function (ResourceType $type) use ($order, $stock, &$free): float {
            $amount = max(0.0, min((float) ($order[$type->value] ?? 0), floor($stock->get($type)), $free));
            $free -= $amount;

            return $amount;
        });
        $planet->setResources($stock->subtract($taken));
        $fleet->setCargo($taken);

        $lines = [];
        foreach (ResourceType::cases() as $type) {
            $wanted = (float) ($order[$type->value] ?? 0);
            if ($wanted > 0) {
                $lines[] = \sprintf('  %s: %s von %s', $type->label(), number_format($taken->get($type), 0, ',', '.'), number_format($wanted, 0, ',', '.'));
            }
        }

        return implode("\n", $lines);
    }

    private function unload(Planet $planet, Fleet $fleet): void
    {
        foreach ($fleet->getShips() as $type => $count) {
            $planet->addShips(ShipType::from($type), $count);
        }
        $planet->setResources($planet->getResources()->add($fleet->getCargo()));
    }

    /** The planet of the fleet's owner at the fleet's position, if any. */
    private function ownPlanetAt(Fleet $fleet): ?Planet
    {
        $planet = $this->planetRepository->findAt($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition());

        return null !== $planet && $planet->getOwner()->getId() === $fleet->getOwner()->getId() ? $planet : null;
    }

    private function idleAtOwnPlanet(Fleet $fleet, User $actor): Planet
    {
        $this->assertOwner($fleet, $actor);
        if (!$fleet->isIdle()) {
            throw new GameException(t('Die Flotte ist unterwegs.'));
        }

        return $this->ownPlanetAt($fleet) ?? throw new GameException(t('Die Flotte steht nicht an einem deiner Planeten.'));
    }

    /** Releases a fleet to all alliance members whose rank has fleet rights (or takes that back). */
    public function releaseToAlliance(Fleet $fleet, User $actor, bool $released): void
    {
        $this->assertOwner($fleet, $actor);
        if ($released && null === $actor->getAlliance()) {
            throw new GameException(t('Du bist in keiner Allianz.'));
        }
        $fleet->setReleasedToAlliance($released);
        $this->em->flush();
    }

    /** Releases a fleet to one member of the own alliance. */
    public function releaseTo(Fleet $fleet, User $actor, User $member): void
    {
        $this->assertOwner($fleet, $actor);
        if ($member->isSameAs($actor) || !$actor->isAllyOf($member)) {
            throw new GameException(t('Flotten können nur an andere Mitglieder deiner Allianz freigegeben werden.'));
        }
        $fleet->releaseTo($member);
        $this->em->flush();
    }

    public function revokeFrom(Fleet $fleet, User $actor, User $member): void
    {
        $this->assertOwner($fleet, $actor);
        $fleet->revokeFrom($member);
        $this->em->flush();
    }

    private function assertControl(Fleet $fleet, User $actor): void
    {
        if (!$fleet->isControllableBy($actor)) {
            throw new GameException(t('Diese Flotte ist für dich nicht freigegeben.'));
        }
    }

    private function assertOwner(Fleet $fleet, User $actor): void
    {
        if ($fleet->getOwner()->getId() !== $actor->getId()) {
            throw new GameException(t('Diese Flotte gehört dir nicht.'));
        }
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > Fleet::MAX_NAME_LENGTH) {
            throw new GameException(t('Der Flottenname muss 1 bis {value} Zeichen lang sein.', ['{value}' => Fleet::MAX_NAME_LENGTH]));
        }

        return $name;
    }

    private function formatResources(Resources $resources): TranslatableInterface
    {
        return Goods::lines($resources);
    }

    /** @param callable(): void $operation */
    private function withEventLock(callable $operation): void
    {
        $lock = $this->lockFactory->createLock('fleet_events');
        $lock->acquire(true);
        try {
            $operation();
        } finally {
            $lock->release();
        }
    }

    /** Times in messages are shown in the reader's time zone (data is stored in UTC). */
    private function localTime(\DateTimeImmutable $at): When
    {
        return new When($at, 'd.m.Y H:i:s');
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
