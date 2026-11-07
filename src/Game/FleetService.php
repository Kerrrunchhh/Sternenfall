<?php

namespace App\Game;

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
use Symfony\Component\Lock\LockFactory;

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
        private Inbox $inbox,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {
    }

    // ------------------------------------------------------------------
    // Named fleets
    // ------------------------------------------------------------------

    /** @param array<string, int> $ships */
    public function create(Planet $planet, string $name, array $ships): void
    {
        $name = $this->validName($name);
        $this->withEventLock(fn () => $this->planets->transaction($planet, function (Planet $planet) use ($name, $ships): void {
            $ships = $this->takeFromHangar($planet, $ships);
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
                    throw new GameException('Wähle mindestens ein Schiff aus.');
                }
                if (!$fleet->hasShips()) {
                    $base->setResources($base->getResources()->add($fleet->getCargo()));
                    $this->fleetRepository->forget($fleet);
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
                throw new GameException('Eine Flotte kann nur aufgeteilt werden, solange sie nicht unterwegs ist.');
            }

            $taken = [];
            foreach ($ships as $type => $count) {
                $shipType = ShipType::tryFrom((string) $type);
                $count = (int) $count;
                if (null === $shipType || $count <= 0) {
                    continue;
                }
                if ($count > $fleet->getShipCount($shipType)) {
                    throw new GameException(\sprintf('Die Flotte hat nur %d × %s.', $fleet->getShipCount($shipType), $shipType->label()));
                }
                $taken[$shipType->value] = $count;
            }
            if ([] === $taken) {
                throw new GameException('Wähle mindestens ein Schiff aus.');
            }
            if (array_sum($taken) >= array_sum($fleet->getShips())) {
                throw new GameException('Mindestens ein Schiff muss in der ursprünglichen Flotte bleiben. Zum Umbenennen nutze „Umbenennen“.');
            }
            foreach ($taken as $type => $count) {
                $fleet->removeShips(ShipType::from($type), $count);
            }

            $this->em->persist(new Fleet($fleet->getOwner(), $name, $fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition(), $taken));
            $this->em->flush();
        });
    }

    /** Puts all ships and cargo of an idle fleet into the hangar of the own planet it is at. */
    public function dissolve(Fleet $fleet, User $actor): void
    {
        $this->withEventLock(function () use ($fleet, $actor): void {
            $this->em->refresh($fleet);
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
     * @return string what happened (for the player)
     */
    /** @param array<string, float> $order goods to buy (mission "Handeln") */
    public function sendFleet(Fleet $fleet, User $actor, int $galaxy, int $system, int $position, FleetMission $mission, Resources $cargo, array $order = []): string
    {
        $result = \sprintf('Flotte „%s“ gestartet: %s nach [%d:%d:%d].', $fleet->getName(), $mission->label(), $galaxy, $system, $position);
        $this->withEventLock(function () use ($fleet, $actor, $galaxy, $system, $position, $mission, $cargo, $order, &$result): void {
            $this->em->refresh($fleet);
            $this->assertControl($fleet, $actor);
            if (!$fleet->isIdle()) {
                throw new GameException('Die Flotte ist bereits unterwegs.');
            }
            if (!$fleet->getOwner()->isSameAs($actor)) {
                $this->inbox->send($fleet->getOwner(), \sprintf('Flotte „%s“ von %s gesteuert', $fleet->getName(), $actor->getUsername()), \sprintf('%s hat deine freigegebene Flotte „%s“ mit dem Auftrag „%s“ nach [%d:%d:%d] geschickt.', $actor->getUsername(), $fleet->getName(), $mission->label(), $galaxy, $system, $position));
            }

            $target = $this->planetRepository->findAt($galaxy, $system, $position);
            if (FleetMission::Fuse === $mission && null !== $target && $target->covers($fleet->getPosition())
                && $target->getGalaxy() === $fleet->getGalaxy() && $target->getSystem() === $fleet->getSystem()) {
                $result = $this->fuseInPlace($fleet, $target);

                return;
            }

            $base = $this->ownPlanetAt($fleet);
            if (null !== $base) {
                $this->planets->transaction($base, fn (Planet $base, \DateTimeImmutable $now) => $this->launch($fleet, $base, $mission, $galaxy, $system, $position, $cargo, $now, $order));

                return;
            }
            $this->launch($fleet, null, $mission, $galaxy, $system, $position, $cargo, $this->now(), $order);
            $this->em->flush();
        });

        return $result;
    }

    /** The fleet's fusionators weld the planet it is waiting at, immediately. */
    private function fuseInPlace(Fleet $fleet, Planet $target): string
    {
        $this->validateMission(FleetMission::Fuse, $fleet->getOwner(), $target, $fleet->getShips(), true);

        $sent = $fleet->getShipCount(ShipType::Fusionator);
        $used = $this->fusion->fuseOnArrival($target, $sent);
        $fleet->removeShips(ShipType::Fusionator, $used);
        if (!$fleet->hasShips()) {
            $this->fleetRepository->forget($fleet);
        }
        $this->em->flush();

        return \sprintf('%s wurde sofort fusioniert, %d von %d Fusionatoren wurden verbraucht.', $target->getName(), $used, $sent)
            .($used < $sent ? \sprintf(' Die übrigen bleiben in der Flotte „%s“.', $fleet->getName()) : '');
    }

    /**
     * "Schiffe zusammenziehen": ships of the chosen types fly from all own planets
     * to the fleet, if they can reach it within $maxMinutes. Every ship type flies
     * at its own speed; each planet pays fuel and supplies for the one-way flight.
     *
     * @param list<ShipType> $types
     *
     * @return list<string> what was sent where (for the player)
     */
    public function gather(Fleet $fleet, User $actor, int $maxMinutes, array $types): array
    {
        if ($maxMinutes < 1) {
            throw new GameException('Bitte gib eine maximale Flugzeit von mindestens einer Minute an.');
        }
        if ([] === $types) {
            throw new GameException('Wähle mindestens einen Schiffstyp aus.');
        }

        $report = [];
        $this->withEventLock(function () use ($fleet, $actor, $maxMinutes, $types, &$report): void {
            $this->em->refresh($fleet);
            $this->assertOwner($fleet, $actor);
            if (!$fleet->isIdle()) {
                throw new GameException('Schiffe können nur zu einer Flotte gezogen werden, die nicht unterwegs ist.');
            }
            $anyPlanet = $actor->getPlanets()->first();
            if (!$anyPlanet instanceof Planet) {
                throw new GameException('Du besitzt keinen Planeten.');
            }

            // One transaction keeps all of the player's planets up to date.
            $this->planets->transaction($anyPlanet, function () use ($fleet, $actor, $maxMinutes, $types, &$report): void {
                $now = $this->now();
                foreach ($actor->getPlanets() as $planet) {
                    foreach ($types as $type) {
                        $count = $planet->getShipCount($type);
                        if ($count < 1 || $type->isStationary()) {
                            continue;
                        }
                        $ships = [$type->value => $count];

                        if ($planet->covers($fleet->getPosition()) && $planet->getGalaxy() === $fleet->getGalaxy() && $planet->getSystem() === $fleet->getSystem()) {
                            $planet->addShips($type, -$count);
                            $fleet->addShips($type, $count);
                            $report[] = \sprintf('%d × %s von %s sofort übernommen', $count, $type->label(), $planet->getName());
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
                            $report[] = \sprintf('%d × %s auf %s: nicht genug Treibstoff oder Proviant', $count, $type->label(), $planet->getName());
                            continue;
                        }
                        $planet->setResources($planet->getResources()->subtract($needed));
                        $planet->addShips($type, -$count);

                        $group = new Fleet($actor, null, $planet->getGalaxy(), $planet->getSystem(), $planet->getPosition(), $ships);
                        $group->depart(FleetMission::Gather, $fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition(), $now, $seconds, $fleet);
                        $this->em->persist($group);
                        $report[] = \sprintf('%d × %s von %s, Ankunft in %s', $count, $type->label(), $planet->getName(), GameExtension::formatDuration($seconds));
                    }
                }
            });
        });

        if ([] === $report) {
            throw new GameException(\sprintf('Keine passenden Schiffe erreichen die Flotte innerhalb von %d Minuten.', $maxMinutes));
        }

        return $report;
    }

    /**
     * Sends an idle fleet to meet a visible foreign fleet at its next stop, just
     * before it gets there: "Abfangen" an attacker (Wächter and Piraten) or
     * "Überfallen" a resource transport (Piraten only).
     */
    public function hunt(Fleet $fleet, User $actor, Fleet $prey, FleetMission $mission): string
    {
        $result = '';
        $this->withEventLock(function () use ($fleet, $actor, $prey, $mission, &$result): void {
            $this->em->refresh($fleet);
            $this->em->refresh($prey);
            $this->assertControl($fleet, $actor);
            if (!$fleet->isIdle()) {
                throw new GameException('Die Flotte ist bereits unterwegs.');
            }
            $owner = $fleet->getOwner();
            $lifeForm = $owner->getLifeForm();

            if (FleetMission::Intercept === $mission) {
                if (!\in_array($lifeForm, [LifeForm::Guardian, LifeForm::Pirate], true)) {
                    throw new GameException('Nur Wächter und Piraten können angreifende Flotten abfangen.');
                }
                if (FleetMission::Attack !== $prey->getMission() || $prey->isReturning()) {
                    throw new GameException('Abfangen kannst du nur Flotten, die gerade angreifen.');
                }
            } elseif (FleetMission::Raid === $mission) {
                if (LifeForm::Pirate !== $lifeForm) {
                    throw new GameException('Nur Piraten können Transporte überfallen.');
                }
                if ($prey->getCargo()->total() < 1) {
                    throw new GameException('Diese Flotte transportiert keine Rohstoffe.');
                }
            } else {
                throw new GameException('Ungültiger Auftrag.');
            }
            if ($prey->getOwner()->isSameAs($owner) || $owner->isAllyOf($prey->getOwner())) {
                throw new GameException('Eigene und verbündete Flotten greifst du nicht an.');
            }
            if (!$this->radar->sees($owner, $prey)) {
                throw new GameException('Diese Flotte ist nicht auf deinem Radar.');
            }

            [$g, $s, $p] = Radar::nextStop($prey);
            $meetAt = $prey->getNextEventAt()->modify('-1 second');
            $go = function (?Planet $base, \DateTimeImmutable $now) use ($fleet, $prey, $mission, $g, $s, $p, $meetAt): void {
                $this->travel($fleet, $base, $mission, $g, $s, $p, new Resources(), $now, $prey, $meetAt);
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
            $result = \sprintf('%s: %s fängt die Flotte von %s bei [%d:%d:%d] ab.', $mission->label(), $fleet->getLabel(), $prey->getOwner()->getUsername(), $g, $s, $p);
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
            if ($fleet->isIdle() || $fleet->isReturning() || $fleet->getArriveAt() <= $now) {
                throw new GameException('Die Flotte ist nicht auf dem Hinflug.');
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
        });
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
    private function launch(Fleet $fleet, ?Planet $base, FleetMission $mission, int $galaxy, int $system, int $position, Resources $cargo, \DateTimeImmutable $now, array $order = []): void
    {
        if (FleetMission::Gather === $mission) {
            throw new GameException('Ungültiger Auftrag.');
        }
        if (!$this->colonizer->isValidSlot($galaxy, $system, $position)) {
            throw new GameException('Diese Koordinaten gibt es nicht.');
        }
        $target = $this->planetRepository->findAt($galaxy, $system, $position);
        $atPlanet = $this->planetRepository->findAt($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition());
        // A fleet can fuse the planet it is waiting at; every other mission needs a different target.
        $sameSpot = $fleet->isAt($galaxy, $system, $position) || (null !== $target && $target->getId() === $atPlanet?->getId());
        if ($sameSpot && FleetMission::Fuse !== $mission) {
            throw new GameException('Die Flotte befindet sich bereits an diesem Ziel.');
        }

        $owner = $fleet->getOwner();
        $ships = $fleet->getShips();
        $post = $this->tradePosts->findAt($galaxy, $system, $position);
        $order = array_filter(array_map(static fn (mixed $v): float => max(0, floor((float) $v)), array_intersect_key($order, array_flip(TradeDeal::goods()))));
        $this->validateMission($mission, $owner, $target, $ships, $fleet->isNamed(), $post, $order);

        foreach (ResourceType::cases() as $type) {
            if ($cargo->get($type) < 0) {
                throw new GameException('Die Ladung darf nicht negativ sein.');
            }
        }
        if (null === $base && !$cargo->isEmpty()) {
            throw new GameException('Ladung kann nur an einem eigenen Planeten aufgenommen werden.');
        }
        $capacity = $this->navigation->cargoCapacity($ships);
        if ($fleet->getCargo()->add($cargo)->total() > $capacity) {
            throw new GameException(\sprintf('Die Ladung übersteigt die Frachtkapazität von %s.', number_format($capacity, 0, ',', '.')));
        }

        $this->travel($fleet, $base, $mission, $galaxy, $system, $position, $cargo, $now);
        if (FleetMission::Trade === $mission) {
            $fleet->setTradeOrder($order);
        }
    }

    /**
     * Pays fuel and provisions for the round trip (from the base planet, or from the
     * fleet's cargo without a base), loads the cargo and puts the fleet on its way.
     *
     * @return int one-way flight time in seconds
     */
    private function travel(Fleet $fleet, ?Planet $base, FleetMission $mission, int $galaxy, int $system, int $position, Resources $cargo, \DateTimeImmutable $now, ?Fleet $targetFleet = null, ?\DateTimeImmutable $mustArriveBefore = null): int
    {
        $ships = $fleet->getShips();
        $distance = $this->navigation->distance($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition(), $galaxy, $system, $position);
        $seconds = $this->navigation->flightSeconds($distance, $this->navigation->slowestSpeed($ships, $fleet->getOwner()));
        if (null !== $mustArriveBefore && $now->modify("+{$seconds} seconds") >= $mustArriveBefore) {
            throw new GameException(\sprintf(
                'Zu langsam: deine Flotte bräuchte %s, das Ziel ist in %s dort.',
                GameExtension::formatDuration($seconds),
                GameExtension::formatDuration($mustArriveBefore->getTimestamp() - $now->getTimestamp()),
            ));
        }
        $fuel = $this->navigation->fuel($ships, $distance);
        $supplies = $this->navigation->supplies($ships, $seconds);
        $travel = $supplies->add(new Resources(hydrogen: $fuel));
        $missing = \sprintf(
            'Treibstoff (%s Wasserstoff) und Proviant (%s Nahrung, %s Wasser)',
            number_format($fuel, 0, ',', '.'),
            number_format($supplies->food, 0, ',', '.'),
            number_format($supplies->water, 0, ',', '.'),
        );

        if (null !== $base) {
            $needed = $cargo->add($travel);
            if (!$base->getResources()->covers($needed)) {
                throw new GameException("Nicht genügend Rohstoffe für Ladung, $missing.");
            }
            $base->setResources($base->getResources()->subtract($needed));
            $fleet->setCargo($fleet->getCargo()->add($cargo));
        } else {
            if (!$fleet->getCargo()->covers($travel)) {
                throw new GameException("Die Ladung der Flotte reicht nicht für $missing.");
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
                throw new GameException(\sprintf('%s steht fest auf dem Planeten und kann nicht in eine Flotte.', $shipType->label()));
            }
            if ($count > $planet->getShipCount($shipType)) {
                throw new GameException(\sprintf('Im Hangar stehen nur %d × %s.', $planet->getShipCount($shipType), $shipType->label()));
            }
            $valid[$shipType->value] = $count;
        }

        if ([] === $valid) {
            throw new GameException('Wähle mindestens ein Schiff aus.');
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

        $error = match ($mission) {
            FleetMission::Transport => null === $target ? 'Am Ziel befindet sich kein Planet.' : null,
            // Named fleets may be parked anywhere; quick dispatches only join the hangar of an own planet.
            FleetMission::Station => $owned || $named ? null : 'Ohne Flottennamen kann nur auf eigenen Planeten stationiert werden.',
            FleetMission::Colonize => match (true) {
                null !== $target => 'Dieser Platz ist bereits besiedelt.',
                null !== $post => 'Auf diesem Platz steht ein Handelsstützpunkt.',
                ($ships[ShipType::ColonyShip->value] ?? 0) < 1 => 'Zum Kolonisieren wird ein Kolonieschiff benötigt.',
                default => null,
            },
            FleetMission::Fuse => match (true) {
                !$owned => 'Fusionieren ist nur auf deinen eigenen Planeten möglich.',
                $target->isIntegrating($this->now()) => \sprintf('%s geht erst am %s in deinen Besitz über.', $target->getName(), $target->getIntegratedAt()->format('d.m.Y H:i')),
                ($ships[ShipType::Fusionator->value] ?? 0) < 1 => 'Zum Fusionieren wird ein Fusionator benötigt.',
                default => null,
            },
            FleetMission::Spy => match (true) {
                null === $target => 'Am Ziel befindet sich kein Planet.',
                $owned => 'Deine eigenen Planeten musst du nicht ausspionieren.',
                ($ships[ShipType::Scout->value] ?? 0) < 1 => 'Zum Spionieren wird mindestens ein Späher benötigt.',
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
                [] === $order => 'Bitte gib in der Bestellung an, was du kaufen möchtest.',
                default => null,
            },
            FleetMission::Attack => match (true) {
                null === $target => 'Dort gibt es keinen Planeten.',
                $owned => 'Deine eigenen Planeten kannst du nicht angreifen.',
                $player->isAllyOf($target->getOwner()) => 'Mitglieder deiner Allianz kannst du nicht angreifen.',
                default => null,
            },
            FleetMission::Salvage => ($ships[ShipType::Salvager->value] ?? 0) < 1 ? 'Zum Bergen wird ein Bergungsschiff benötigt.' : null,
            FleetMission::Gather, FleetMission::Intercept, FleetMission::Raid => 'Ungültiger Auftrag.',
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
        $name = $fleet->isNamed() ? 'Flotte „'.$fleet->getName().'“' : 'Deine Flotte';

        switch ($fleet->getMission()) {
            case FleetMission::Transport:
                if (null === $target) {
                    $this->inbox->send($player, "Transport nach $where gescheitert", "Am Ziel befand sich kein Planet mehr. $name kehrt mit ihrer Ladung zurück.");
                    break;
                }
                $cargo = $fleet->getCargo();
                $this->planets->transaction($target, static fn (Planet $t) => $t->setResources($t->getResources()->add($cargo)));
                $fleet->setCargo(new Resources());
                $this->inbox->send($player, "Transport nach $where angekommen", "$name hat folgende Ladung bei {$target->getName()} $where abgeliefert:\n".$this->formatResources($cargo));
                if ($target->getOwner()->getId() !== $player->getId()) {
                    $this->inbox->send($target->getOwner(), "Lieferung von {$player->getUsername()}", "{$player->getUsername()} hat dir auf {$target->getName()} $where Rohstoffe geliefert:\n".$this->formatResources($cargo));
                }
                break;

            case FleetMission::Station:
                if ($fleet->isNamed()) {
                    $fleet->settleAtTarget();
                    $this->inbox->send($player, "$name auf $where stationiert", "$name ist eingetroffen und wartet dort auf neue Befehle.");

                    return;
                }
                if (null === $target || $target->getOwner()->getId() !== $player->getId()) {
                    $this->inbox->send($player, "Stationierung auf $where gescheitert", 'Der Planet gehört dir nicht mehr. Die Flotte kehrt zurück.');
                    break;
                }
                $this->planets->transaction($target, fn (Planet $t) => $this->unload($t, $fleet));
                $this->fleetRepository->forget($fleet);
                $this->inbox->send($player, "Flotte auf $where stationiert", "Deine Schiffe sind auf {$target->getName()} $where eingetroffen und im Hangar stationiert.");

                return;

            case FleetMission::Supply:
                $post = $this->tradePosts->findAt($fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition());
                if (null === $post || !$post->getOwner()->isSameAs($player) || !$post->isActive()) {
                    $this->inbox->send($player, "Belieferung von $where gescheitert", "Dort steht kein aktiver Handelsstützpunkt von dir mehr. $name kehrt zurück.");
                    break;
                }
                $delivered = $this->trade->supply($post, $fleet);
                $this->inbox->send($player, "{$post->getName()} beliefert", "$name hat an {$post->getName()} $where abgeliefert: $delivered.");
                if (!$fleet->hasShips()) {
                    $this->fleetRepository->forget($fleet);

                    return;
                }
                break;

            case FleetMission::Trade:
                $post = $this->tradePosts->findAt($fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition());
                if (null === $post || !$post->isAccessibleTo($player)) {
                    $this->inbox->send($player, "Handel an $where gescheitert", "Der Handelsstützpunkt ist nicht mehr da oder für dich gesperrt. $name kehrt zurück.");
                    break;
                }
                $deal = $this->trade->buy($post, $fleet);
                $this->inbox->send($player, "Handel an {$post->getName()} $where", $deal->isEmpty()
                    ? "Es konnte nichts gekauft werden (Lager, Credits oder Frachtraum reichten nicht). $name kehrt zurück."
                    : \sprintf("Gekauft:\n%s\n\nPreis: %s Credits. %s kehrt mit der Ware zurück.", TradeService::describe($deal), number_format($deal->price, 0, ',', '.'), $name));
                break;

            case FleetMission::Colonize:
                if (null !== $this->tradePosts->findAt($fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition())) {
                    $this->inbox->send($player, "Kolonisierung von $where gescheitert", "Auf dem Platz steht inzwischen ein Handelsstützpunkt. $name kehrt zurück.");
                    break;
                }
                if (null !== $target) {
                    $this->inbox->send($player, "Kolonisierung von $where gescheitert", "Der Platz wurde inzwischen besiedelt. $name kehrt zurück.");
                    break;
                }
                $settlers = ShipType::ColonyShip->spec()->crew;
                $this->colonizer->foundColony($player, $fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition(), $settlers, $fleet->getCargo());
                $fleet->removeShips(ShipType::ColonyShip, 1);
                $fleet->setCargo(new Resources());
                $this->inbox->send($player, "Neue Kolonie auf $where", "Dein Kolonieschiff ist gelandet. $settlers Siedler haben eine neue Kolonie gegründet und die Ladung ausgeladen.");
                if (!$fleet->hasShips()) {
                    $this->fleetRepository->forget($fleet);

                    return;
                }
                break;

            case FleetMission::Fuse:
                if (null === $target || $target->getOwner()->getId() !== $player->getId()) {
                    $this->inbox->send($player, "Fusion auf $where gescheitert", 'Der Planet gehört dir nicht mehr. Der Fusionator kehrt zurück.');
                    break;
                }
                $sent = $fleet->getShipCount(ShipType::Fusionator);
                try {
                    $used = $this->fusion->fuseOnArrival($target, $sent);
                } catch (GameException $e) {
                    $this->inbox->send($player, "Fusion auf $where gescheitert", $e->getMessage()."\n$name kehrt zurück.");
                    break;
                }
                $fleet->removeShips(ShipType::Fusionator, $used);
                if ($used < $sent) {
                    $this->inbox->send($player, "Überzählige Fusionatoren von $where", \sprintf('Für die Fusion wurden nur %d von %d Fusionatoren gebraucht. %s kehrt mit dem Rest zurück.', $used, $sent, $name));
                }
                if (!$fleet->hasShips()) {
                    $this->fleetRepository->forget($fleet);

                    return;
                }
                break;

            case FleetMission::Spy:
                if (null === $target) {
                    $this->inbox->send($player, "Spionage von $where gescheitert", 'Am Ziel befand sich kein Planet mehr.');
                    break;
                }
                $this->planets->transaction($target, fn (Planet $t) => $this->inbox->send($player, "Spionagebericht {$t->getName()} $where", $this->spyReport($t)));
                $this->inbox->send($target->getOwner(), 'Späher gesichtet', "Über {$target->getName()} $where wurden Späher von {$player->getUsername()} gesichtet.");
                break;

            case FleetMission::Attack:
                if (null === $target || $target->getOwner()->isSameAs($player) || $player->isAllyOf($target->getOwner())) {
                    $this->inbox->send($player, "Angriff auf $where abgebrochen", "Das Ziel ist kein gegnerischer Planet mehr. $name kehrt zurück.");
                    break;
                }
                $this->combat->attackPlanet($fleet, $target);
                if (!$fleet->hasShips()) {
                    $this->fleetRepository->forget($fleet);

                    return;
                }
                break;

            case FleetMission::Salvage:
                $this->inbox->send($player, "Bergung bei $where", $this->combat->salvage($fleet)." $name kehrt zurück.");
                break;

            case FleetMission::Intercept:
            case FleetMission::Raid:
                $prey = $fleet->getTargetFleet();
                $raid = FleetMission::Raid === $fleet->getMission();
                $stillComing = null !== $prey && $prey->isMoving() && Radar::nextStop($prey) === [$fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition()]
                    // Compare with the moment of the ambush, not "now": events are resolved lazily.
                    && $prey->getNextEventAt() >= $fleet->getArriveAt() && ($raid ? $prey->getCargo()->total() >= 1 : FleetMission::Attack === $prey->getMission() && !$prey->isReturning());
                if (!$stillComing) {
                    $this->inbox->send($player, ($raid ? 'Überfall' : 'Abfangen')." bei $where verpasst", "Das Ziel ist nicht mehr dorthin unterwegs. $name kehrt zurück.");
                    break;
                }
                $raid ? $this->combat->raid($fleet, $prey) : $this->combat->intercept($fleet, $prey);
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
                $this->inbox->send($player, "Zusammenziehen nach $where gescheitert", 'Die Zielflotte war nicht mehr dort. Die Schiffe kehren zurück.');
                break;
        }

        $fleet->startReturn();
    }

    /** A returning fleet reaches its point of departure. */
    private function land(Fleet $fleet): void
    {
        if ($fleet->isNamed()) {
            $fleet->land();

            return;
        }

        // Quick dispatches dissolve into the hangar of the own planet they started from.
        $home = $this->ownPlanetAt($fleet);
        if (null === $home) {
            $fleet->land();
            $fleet->setName('Gestrandete Flotte');
            $this->inbox->send($fleet->getOwner(), 'Flotte gestrandet', \sprintf('Deine Schiffe sind zu [%s] zurückgekehrt, aber der Planet gehört dir nicht mehr. Sie warten dort als „Gestrandete Flotte“.', $fleet->getCoordinates()));

            return;
        }
        $this->planets->transaction($home, fn (Planet $home) => $this->unload($home, $fleet));
        $this->fleetRepository->forget($fleet);
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
            throw new GameException('Die Flotte ist unterwegs.');
        }

        return $this->ownPlanetAt($fleet) ?? throw new GameException('Die Flotte steht nicht an einem deiner Planeten.');
    }

    /** Releases a fleet to all alliance members whose rank has fleet rights (or takes that back). */
    public function releaseToAlliance(Fleet $fleet, User $actor, bool $released): void
    {
        $this->assertOwner($fleet, $actor);
        if ($released && null === $actor->getAlliance()) {
            throw new GameException('Du bist in keiner Allianz.');
        }
        $fleet->setReleasedToAlliance($released);
        $this->em->flush();
    }

    /** Releases a fleet to one member of the own alliance. */
    public function releaseTo(Fleet $fleet, User $actor, User $member): void
    {
        $this->assertOwner($fleet, $actor);
        if ($member->isSameAs($actor) || !$actor->isAllyOf($member)) {
            throw new GameException('Flotten können nur an andere Mitglieder deiner Allianz freigegeben werden.');
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
            throw new GameException('Diese Flotte ist für dich nicht freigegeben.');
        }
    }

    private function assertOwner(Fleet $fleet, User $actor): void
    {
        if ($fleet->getOwner()->getId() !== $actor->getId()) {
            throw new GameException('Diese Flotte gehört dir nicht.');
        }
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > Fleet::MAX_NAME_LENGTH) {
            throw new GameException(\sprintf('Der Flottenname muss 1 bis %d Zeichen lang sein.', Fleet::MAX_NAME_LENGTH));
        }

        return $name;
    }

    private function spyReport(Planet $planet): string
    {
        $lines = [
            \sprintf('Planet %s [%s] von %s', $planet->getName(), $planet->getCoordinates(), $planet->getOwner()->getUsername()),
            '',
            'Rohstoffe:',
            $this->formatResources($planet->getResources()),
            '',
            'Bevölkerung: '.number_format($planet->getPopulation(), 0, ',', '.'),
            '',
            'Gebäude:',
        ];
        foreach (BuildingType::cases() as $type) {
            if ($planet->getBuildingLevel($type) > 0) {
                $lines[] = \sprintf('  %s: Stufe %d', $type->label(), $planet->getBuildingLevel($type));
            }
        }
        $lines[] = '';
        $lines[] = 'Schiffe:';
        $ships = $planet->getShips();
        foreach (ShipType::cases() as $type) {
            if (($ships[$type->value] ?? 0) > 0) {
                $lines[] = \sprintf('  %s: %s', $type->label(), number_format($ships[$type->value], 0, ',', '.'));
            }
        }
        if ([] === $ships) {
            $lines[] = '  keine';
        }
        $lines[] = '';
        $lines[] = 'Forschung:';
        foreach (ResearchType::cases() as $type) {
            if ($planet->getOwner()->getResearchLevel($type) > 0) {
                $lines[] = \sprintf('  %s: Stufe %d', $type->label(), $planet->getOwner()->getResearchLevel($type));
            }
        }

        return implode("\n", $lines);
    }
    private function formatResources(Resources $resources): string
    {
        $lines = [];
        foreach (ResourceType::cases() as $type) {
            $lines[] = \sprintf('  %s: %s', $type->label(), number_format($resources->get($type), 0, ',', '.'));
        }

        return implode("\n", $lines);
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

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
