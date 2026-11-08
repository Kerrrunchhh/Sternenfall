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
        private Espionage $espionage,
        private Inbox $inbox,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {
    }

    // ------------------------------------------------------------------
    // Probes: the only ships that start straight from a hangar
    // ------------------------------------------------------------------

    /**
     * Sends one scout to spy on a slot, from the own planet nearest to it that has
     * a scout in its hangar. The scout is used up on arrival.
     */
    public function quickSpy(User $player, int $galaxy, int $system, int $position): string
    {
        if (!$this->colonizer->isValidSlot($galaxy, $system, $position)) {
            throw new GameException('Diese Koordinaten gibt es nicht.');
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
            throw new GameException('In deinen Hangars steht keine Sonde. Baue welche in der Werft.');
        }

        $fleet = null;
        // A real closure (not fn()): arrow functions capture by value and would lose $fleet.
        $this->withEventLock(function () use ($origin, $galaxy, $system, $position, &$fleet): void {
            $this->planets->transaction($origin, function (Planet $origin, \DateTimeImmutable $now) use ($galaxy, $system, $position, &$fleet): void {
            if ($origin->getShipCount(ShipType::Scout) < 1) {
                throw new GameException('Die Sonde ist inzwischen nicht mehr im Hangar.');
            }
            $origin->addShips(ShipType::Scout, -1);
            $fleet = new Fleet($origin->getOwner(), null, $origin->getGalaxy(), $origin->getSystem(), $origin->getPosition(), [ShipType::Scout->value => 1]);
            $this->launch($fleet, $origin, FleetMission::Spy, $galaxy, $system, $position, new Resources(), $now);
            $this->em->persist($fleet);
            });
        });

        return \sprintf('Sonde von %s [%s] gestartet nach [%d:%d:%d], Ankunft %s.', $origin->getName(), $origin->getCoordinates(), $galaxy, $system, $position, $this->localTime($fleet->getArriveAt()));
    }

    /**
     * Sends one scout from the nearest own hangar towards a visible foreign fleet:
     * it meets the fleet at its next stop, before the fleet gets there, and reports
     * what the fleet consists of.
     */
    public function spyFleet(User $player, Fleet $target): string
    {
        if ($target->getOwner()->isSameAs($player) || !$target->isMoving() || !$this->radar->sees($player, $target)) {
            throw new GameException('Diese Flotte ist nicht auf deinem Radar.');
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
            throw new GameException('In deinen Hangars steht keine Sonde. Baue welche in der Werft.');
        }

        $probe = null;
        $this->withEventLock(function () use ($origin, $target, $galaxy, $system, $position, &$probe): void {
            $this->em->refresh($target);
            $this->planets->transaction($origin, function (Planet $origin, \DateTimeImmutable $now) use ($target, $galaxy, $system, $position, &$probe): void {
                if ($origin->getShipCount(ShipType::Scout) < 1) {
                    throw new GameException('Die Sonde ist inzwischen nicht mehr im Hangar.');
                }
                $origin->addShips(ShipType::Scout, -1);
                $probe = new Fleet($origin->getOwner(), null, $origin->getGalaxy(), $origin->getSystem(), $origin->getPosition(), [ShipType::Scout->value => 1]);
                $this->travel($probe, $origin, FleetMission::Spy, $galaxy, $system, $position, new Resources(), $now, $target, $target->getNextEventAt());
                $this->em->persist($probe);
            });
        });

        return \sprintf('Sonde von %s [%s] fliegt der Flotte von %s entgegen, Treffpunkt [%d:%d:%d] um %s.', $origin->getName(), $origin->getCoordinates(), $target->getOwner()->getUsername(), $galaxy, $system, $position, $this->localTime($probe->getArriveAt()));
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
    /**
     * Merges fleets waiting at the same place into the first one (ships and cargo).
     *
     * @param list<Fleet> $fleets
     */
    public function merge(array $fleets, User $actor): Fleet
    {
        if (\count($fleets) < 2) {
            throw new GameException('Wähle mindestens zwei Flotten zum Zusammenlegen aus.');
        }
        $this->withEventLock(function () use ($fleets, $actor): void {
            foreach ($fleets as $fleet) {
                $this->em->refresh($fleet);
                $this->assertOwner($fleet, $actor);
                if (!$fleet->isIdle()) {
                    throw new GameException(\sprintf('%s ist unterwegs und kann nicht zusammengelegt werden.', $fleet->getLabel()));
                }
                if (!$fleet->isAt($fleets[0]->getGalaxy(), $fleets[0]->getSystem(), $fleets[0]->getPosition())) {
                    throw new GameException('Nur Flotten am selben Ort können zusammengelegt werden.');
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
    public function sendFleet(Fleet $fleet, User $actor, int $galaxy, int $system, int $position, FleetMission $mission, Resources $cargo, array $order = [], float $speedPercent = 100.0): string
    {
        $result = \sprintf('Flotte „%s“ gestartet: %s nach [%d:%d:%d].', $fleet->getName(), $mission->label(), $galaxy, $system, $position);
        $this->withEventLock(function () use ($fleet, $actor, $galaxy, $system, $position, $mission, $cargo, $order, $speedPercent, &$result): void {
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
                $this->planets->transaction($base, fn (Planet $base, \DateTimeImmutable $now) => $this->launch($fleet, $base, $mission, $galaxy, $system, $position, $cargo, $now, $order, $speedPercent));

                return;
            }
            $this->launch($fleet, null, $mission, $galaxy, $system, $position, $cargo, $this->now(), $order, $speedPercent);
            $this->em->flush();
        });

        if ($fleet->isMoving()) {
            $result .= \sprintf(' %sAnkunft: %s.', $speedPercent < 100 ? \sprintf('Mit %s %% Geschwindigkeit. ', number_format($speedPercent, 2, ',', '.')) : '', $this->localTime($fleet->getArriveAt()));
        }

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
                        if ($count < 1 || $type->isStationary() || $type->isProbe()) {
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
    public function hunt(Fleet $fleet, User $actor, Fleet $prey, FleetMission $mission, float $speedPercent = 100.0): string
    {
        $result = '';
        $this->withEventLock(function () use ($fleet, $actor, $prey, $mission, $speedPercent, &$result): void {
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
            $result = \sprintf('%s: %s fängt die Flotte von %s bei [%d:%d:%d] ab.', $mission->label(), $fleet->getLabel(), $prey->getOwner()->getUsername(), $g, $s, $p);
        });

        return $result;
    }

    /**
     * Places where a fusionator of the player stands, as sources for hovering:
     * hangars of own planets and own waiting fleets.
     *
     * @return array<string, array{label: string, galaxy: int, system: int, position: int}> key "planet:ID" / "fleet:ID"
     */
    public function hoverSources(User $player): array
    {
        $sources = [];
        foreach ($player->getPlanets() as $planet) {
            if ($planet->getShipCount(ShipType::Fusionator) > 0) {
                $sources['planet:'.$planet->getId()] = ['label' => \sprintf('Hangar %s [%s]', $planet->getName(), $planet->getCoordinates()), 'galaxy' => $planet->getGalaxy(), 'system' => $planet->getSystem(), 'position' => $planet->getPosition()];
            }
        }
        foreach ($this->fleetRepository->findBy(['owner' => $player, 'mission' => null]) as $fleet) {
            if ($fleet->getShipCount(ShipType::Fusionator) > 0) {
                $sources['fleet:'.$fleet->getId()] = ['label' => \sprintf('Flotte %s [%s]', $fleet->getLabel(), $fleet->getCoordinates()), 'galaxy' => $fleet->getGalaxy(), 'system' => $fleet->getSystem(), 'position' => $fleet->getPosition()];
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
    public function hover(Fleet $fleet, User $actor, string $sourceKey): string
    {
        $result = '';
        $this->withEventLock(function () use ($fleet, $actor, $sourceKey, &$result): void {
            $this->em->refresh($fleet);
            $this->assertOwner($fleet, $actor);
            if (!$fleet->isIdle() || !$fleet->isNamed()) {
                throw new GameException('Nur wartende Flotten können schweben.');
            }
            $source = $this->hoverSources($actor)[$sourceKey] ?? throw new GameException('Dort steht kein Fusionator von dir.');
            $seconds = $this->hoverSeconds($fleet, $source);
            $supplies = $this->navigation->supplies($fleet->getShips(), $seconds, oneWay: true);

            $go = function (?Planet $base, \DateTimeImmutable $now) use ($fleet, $seconds, $supplies): void {
                $payer = null !== $base ? $base->getResources() : $fleet->getCargo();
                if (!$payer->covers($supplies)) {
                    throw new GameException(\sprintf('Für das Schweben fehlt Proviant: %s Nahrung und %s Wasser.', number_format($supplies->food, 0, ',', '.'), number_format($supplies->water, 0, ',', '.')));
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
            $result = \sprintf('%s schwebt %s lang unsichtbar über [%s].', $fleet->getLabel(), GameExtension::formatDuration($seconds), $fleet->getCoordinates());
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
    private function launch(Fleet $fleet, ?Planet $base, FleetMission $mission, int $galaxy, int $system, int $position, Resources $cargo, \DateTimeImmutable $now, array $order = [], float $speedPercent = 100.0): void
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
        // Transport or station to a trade post: the fleet flies there and waits to trade.
        if (null !== $post && null === $target && \in_array($mission, [FleetMission::Transport, FleetMission::TransportStation, FleetMission::Station], true)) {
            $mission = FleetMission::Trade;
        }
        $order = array_filter(array_map(static fn (mixed $v): float => max(0, floor((float) $v)), array_intersect_key($order, array_flip(TradeDeal::goods()))));
        $this->validateMission($mission, $owner, $target, $ships, $fleet->isNamed(), $post, $order);
        // Cargo travels openly: a fleet with cargo has to transport, it can't be stationed.
        if (FleetMission::Station === $mission && !$fleet->getCargo()->add($cargo)->isEmpty()) {
            throw new GameException('Eine Flotte mit Ladung kann nicht stationieren – schicke sie mit „Transport“ (dabei ist sie auf dem Radar sichtbar) oder lade die Ladung vorher ab.');
        }

        foreach (ResourceType::cases() as $type) {
            if ($cargo->get($type) < 0) {
                throw new GameException('Die Ladung darf nicht negativ sein.');
            }
        }
        if (null === $base && !$cargo->isEmpty()) {
            throw new GameException('Ladung kann nur an einem eigenen Planeten aufgenommen werden.');
        }
        $capacity = $this->navigation->cargoCapacity($ships);
        if (0 === $capacity && !$cargo->isEmpty()) {
            throw new GameException('Nur Handelsschiffe (Frachter, Großfrachter) können Fracht transportieren. Nimm einen Frachter in die Flotte auf.');
        }
        if ($fleet->getCargo()->add($cargo)->total() > $capacity) {
            throw new GameException(\sprintf('Die Ladung übersteigt die Frachtkapazität von %s.', number_format($capacity, 0, ',', '.')));
        }

        // Leaving an own planet for a trade post: remember it to fly back there later.
        if (FleetMission::Trade === $mission && null !== $base) {
            $fleet->rememberHome($base->getGalaxy(), $base->getSystem(), $base->getPosition());
        }
        $this->travel($fleet, $base, $mission, $galaxy, $system, $position, $cargo, $now, speedPercent: $speedPercent);
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
    private function travel(Fleet $fleet, ?Planet $base, FleetMission $mission, int $galaxy, int $system, int $position, Resources $cargo, \DateTimeImmutable $now, ?Fleet $targetFleet = null, ?\DateTimeImmutable $mustArriveBefore = null, float $speedPercent = 100.0): int
    {
        $ships = $fleet->getShips();
        $distance = $this->navigation->distance($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition(), $galaxy, $system, $position);
        $seconds = $this->navigation->flightSeconds($distance, $this->navigation->slowestSpeed($ships, $fleet->getOwner()), $speedPercent);
        if (null !== $mustArriveBefore && $now->modify("+{$seconds} seconds") >= $mustArriveBefore) {
            throw new GameException(\sprintf(
                'Zu langsam: deine Flotte bräuchte %s, das Ziel ist in %s dort.',
                GameExtension::formatDuration($seconds),
                GameExtension::formatDuration($mustArriveBefore->getTimestamp() - $now->getTimestamp()),
            ));
        }
        // Probes never come back, so they only need fuel and provisions for one way.
        $oneWay = FleetMission::Spy === $mission;
        $fuel = $this->navigation->fuel($ships, $distance, $oneWay);
        $supplies = $this->navigation->supplies($ships, $seconds, $oneWay);
        $travel = $supplies->add(new Resources(hydrogen: $fuel));
        $missing = \sprintf(
            'Treibstoff (%s Wasserstoff) und Proviant (%s Nahrung, %s Wasser)',
            number_format($fuel, 0, ',', '.'),
            number_format($supplies->food, 0, ',', '.'),
            number_format($supplies->water, 0, ',', '.'),
        );

        // Leaving a trade post costs nothing: the post fuels and provisions departing fleets.
        if (null === $base && null !== $this->tradePosts->findAt($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition())) {
            $travel = new Resources();
        }

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
            if ($shipType->isProbe()) {
                throw new GameException(\sprintf('%s können nicht in Flotten aufgenommen werden. Sie starten über 🔭 direkt aus dem Hangar.', $shipType->label()));
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

        // Only trade fleets dock at trade posts: freighters and large freighters.
        if (null !== $post && \in_array($mission, [FleetMission::Trade, FleetMission::Supply, FleetMission::Station], true)) {
            foreach ($ships as $type => $count) {
                $shipType = ShipType::from($type);
                if ($count > 0 && !$shipType->carriesCargo()) {
                    throw new GameException(\sprintf('Zu Handelsstützpunkten dürfen nur Handelsflotten (Frachter, Großfrachter). %s gehört nicht dazu. Fusionatoren verkaufst du auf dem Fusionator-Markt.', $shipType->label()));
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
                    => \sprintf('Deine Kolonisationstechnik erlaubt %d Planetenplätze (verschweißte zählen einzeln) – sie sind belegt oder schon von Kolonieschiffen angeflogen. Erforsche „Kolonisationstechnik“ weiter.', $this->economy->colonySlots($player)),
                default => null,
            },
            FleetMission::Fuse => match (true) {
                !$owned => 'Fusionieren ist nur auf deinen eigenen Planeten möglich.',
                $target->isIntegrating($this->now()) => \sprintf('%s geht erst am %s in deinen Besitz über.', $target->getName(), $target->getIntegratedAt()->format('d.m.Y H:i')),
                ($ships[ShipType::Fusionator->value] ?? 0) < 1 => 'Zum Fusionieren wird ein Fusionator benötigt.',
                // Checked already now: planet above, owner, and whether both together are a legal fusion.
                default => $this->fusion->plan($target)['reason'],
            },
            FleetMission::Spy => match (true) {
                $owned => 'Deine eigenen Planeten musst du nicht ausspionieren.',
                ($ships[ShipType::Scout->value] ?? 0) < 1 => 'Zum Spionieren wird mindestens eine Sonde benötigt.',
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
                null === $target => 'Dort gibt es keinen Planeten.',
                // A fused home planet can lose its lower planets, only a single one is untouchable.
                $target->isHomeworld() && !$target->isFused() && ($ships[ShipType::InvasionShip->value] ?? 0) > 0 && !$owned
                    => \sprintf('%s [%s] ist der Heimatplanet von %s und kann nicht erobert werden. Lass die Invasionsschiffe zu Hause oder wähle ein anderes Ziel.', $target->getName(), $target->getCoordinates(), $target->getOwner()->getUsername()),
                $owned => 'Deine eigenen Planeten kannst du nicht angreifen.',
                $player->isAllyOf($target->getOwner()) => 'Mitglieder deiner Allianz kannst du nicht angreifen.',
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
        $name = $fleet->isNamed() ? 'Flotte „'.$fleet->getName().'“' : 'Deine Flotte';

        switch ($fleet->getMission()) {
            case FleetMission::Transport:
            case FleetMission::TransportStation:
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
                // "Transportieren und stationieren": the fleet stays there instead of flying back.
                if (FleetMission::TransportStation === $fleet->getMission()) {
                    $fleet->forgetHome();
                    if ($fleet->isNamed()) {
                        $fleet->settleAtTarget();
                        $this->inbox->send($player, "$name auf $where stationiert", "$name bleibt bei {$target->getName()} $where und wartet dort auf neue Befehle.");

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
                $this->inbox->send($player, "$name nach $where gesprungen", \sprintf('%s ist bei %s %s eingetroffen und wartet dort auf neue Befehle.', $name, $target?->getName() ?? 'dem Planeten', $where));

                return;

            case FleetMission::Station:
                if ($fleet->isNamed()) {
                    $fleet->settleAtTarget();
                    // At an own planet the cargo goes into its storage.
                    $cargo = $fleet->getCargo();
                    $delivered = '';
                    if (!$cargo->isEmpty() && null !== $target && $target->getOwner()->isSameAs($player)) {
                        $this->planets->transaction($target, static fn (Planet $t) => $t->setResources($t->getResources()->add($cargo)));
                        $fleet->setCargo(new Resources());
                        $delivered = "\nDie Ladung wurde ins Lager von {$target->getName()} gebracht:\n".$this->formatResources($cargo);
                    }
                    $this->inbox->send($player, "$name auf $where stationiert", "$name ist eingetroffen und wartet dort auf neue Befehle.".$delivered);

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
                // The fleet waits at the post; buying and selling happen on the trade post page.
                $fleet->settleAtTarget();
                $this->inbox->send($player, "$name am Handelsstützpunkt {$post->getName()} $where", "$name ist bei {$post->getName()} $where eingetroffen und wartet dort. Kaufen und verkaufen kannst du auf der Seite des Handelsstützpunkts (im Universum anklicken).");

                return;

            case FleetMission::Colonize:
                if (null !== $this->tradePosts->findAt($fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition())) {
                    $this->inbox->send($player, "Kolonisierung von $where gescheitert", "Auf dem Platz steht inzwischen ein Handelsstützpunkt. $name kehrt zurück.");
                    break;
                }
                if (null !== $target) {
                    $this->inbox->send($player, "Kolonisierung von $where gescheitert", "Der Platz wurde inzwischen besiedelt. $name kehrt zurück.");
                    break;
                }
                if (Economy::ownedSlots($player) >= $this->economy->colonySlots($player)) {
                    $this->inbox->send($player, "Kolonisierung von $where gescheitert", \sprintf('Deine Kolonisationstechnik erlaubt nur %d Planetenplätze. %s kehrt zurück.', $this->economy->colonySlots($player), $name));
                    break;
                }
                $settlers = ShipType::ColonyShip->spec()->crew;
                $this->colonizer->foundColony($player, $fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition(), $settlers, $fleet->getCargo());
                $fleet->removeShips(ShipType::ColonyShip, 1);
                $fleet->setCargo(new Resources());
                $this->inbox->send($player, "Neue Kolonie auf $where", "Dein Kolonieschiff ist auf $where gelandet. $settlers Siedler haben dort eine neue Kolonie gegründet und die Ladung ausgeladen.");
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
                [$g, $s, $p] = [$fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition()];
                if (null !== $prey = $fleet->getTargetFleet()) {
                    // Probe sent after a fleet: it must still be on its way to this place.
                    if ($prey->isMoving() && Radar::nextStop($prey) === [$g, $s, $p] && $prey->getNextEventAt() >= $fleet->getArriveAt()) {
                        $this->inbox->send($player, 'Flotte von '.$prey->getOwner()->getUsername().' ausgespäht', $this->espionage->scoutFleet($player, $prey));
                    } else {
                        $this->inbox->send($player, "Sonde bei $where: Flotte verpasst", 'Die Flotte war nicht mehr dorthin unterwegs. Die Sonde hat nichts entdeckt und ist verglüht.');
                    }
                } elseif (null === $target) {
                    $this->inbox->send($player, "Spionagebericht $where", $this->espionage->report($player, $g, $s, $p, null));
                } else {
                    $this->planets->transaction($target, fn (Planet $t) => $this->inbox->send($player, "Spionagebericht {$t->getName()} $where", $this->espionage->report($player, $g, $s, $p, $t)));
                    $this->inbox->send($target->getOwner(), 'Sonden gesichtet', "Über {$target->getName()} $where wurden Sonden von {$player->getUsername()} gesichtet.");
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

    /** Times in messages are shown in German time (data is stored in UTC). */
    private function localTime(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i:s');
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
