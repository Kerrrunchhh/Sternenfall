<?php

namespace App\Game;

use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\User;
use App\Repository\FleetRepository;
use App\Repository\PlanetRepository;
use Psr\Clock\ClockInterface;

/**
 * What a player can see of other fleets. Waiting fleets are invisible. Moving
 * fleets become visible when they attack or carry resources, and only while they are
 * within the player's radar: radar 1 covers the own systems, every 5 further levels one
 * more system to the left and right. Where a fleet is follows from its flight times: it
 * moves evenly from system to system (positionAt()).
 */
final readonly class Radar
{
    public function __construct(
        private FleetRepository $fleets,
        private PlanetRepository $planets,
        private Espionage $espionage,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Where a moving fleet is: galaxy and system (rounded to the nearest one it passes).
     * Out and back it moves evenly between its start and its target; a recalled fleet turns
     * where it was. Between galaxies it counts as at its start for the first half of the
     * flight and at its target for the second.
     *
     * @return array{int, int}
     */
    public static function positionAt(Fleet $fleet, \DateTimeImmutable $now): array
    {
        $from = [$fleet->getGalaxy(), $fleet->getSystem()];
        $to = [(int) $fleet->getTargetGalaxy(), (int) $fleet->getTargetSystem()];
        $depart = $fleet->getDepartAt()?->getTimestamp() ?? $now->getTimestamp();
        $arrive = $fleet->getArriveAt()?->getTimestamp() ?? $depart;
        $flight = max(1, $arrive - $depart);
        $t = $now->getTimestamp();

        if (!$fleet->isReturning()) {
            $share = ($t - $depart) / $flight;
        } else {
            $return = $fleet->getReturnAt()?->getTimestamp() ?? $t;
            // Recalled: the way back takes as long as the way out so far, so it turned halfway
            // between departure and return. Otherwise it flies the whole way back at the end.
            $turnedAt = $return - $depart < 2 * $flight ? intdiv($return + $depart, 2) : $return - $flight;
            $turnShare = min(1.0, max(0.0, ($turnedAt - $depart) / $flight));
            $back = max(1, $return - $turnedAt);
            $share = $turnShare * (1 - ($t - $turnedAt) / $back);
        }
        $share = min(1.0, max(0.0, $share));

        if ($from[0] !== $to[0]) {
            return $share < 0.5 ? $from : $to;
        }

        return [$from[0], (int) round($from[1] + ($to[1] - $from[1]) * $share)];
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock?->now() ?? new \DateTimeImmutable());
    }

    /** Galaxy and system where the fleet is now. @return array{int, int} */
    public function position(Fleet $fleet): array
    {
        return self::positionAt($fleet, $this->now());
    }

    /** Systems to the left and right that are covered, or null without radar. */
    public static function range(int $level): ?int
    {
        return $level < 1 ? null : intdiv($level, 5);
    }

    public function covers(User $player, int $galaxy, int $system): bool
    {
        return [] !== $this->coveringPlanets($player, $galaxy, $system);
    }

    /** @return list<Planet> the player's planets whose radar reaches the system */
    public function coveringPlanets(User $player, int $galaxy, int $system): array
    {
        $range = self::range($player->getResearchLevel(ResearchType::Radar));
        if (null === $range) {
            return [];
        }

        return array_values(array_filter(
            $player->getPlanets()->toArray(),
            static fn (Planet $planet): bool => $planet->getGalaxy() === $galaxy && abs($planet->getSystem() - $system) <= $range,
        ));
    }

    /**
     * Fleets that give themselves away: attackers, resource transports and trade fleets
     * on their way back from a transport, even empty.
     */
    public static function emits(Fleet $fleet): bool
    {
        if (!$fleet->isMoving() || \in_array($fleet->getMission(), [FleetMission::Gather, FleetMission::Hover], true)) {
            return false;
        }
        // Trade fleets on their way back, and pickups both ways, fly openly.
        if (self::isReturningTrader($fleet) || FleetMission::Pickup === $fleet->getMission()) {
            return true;
        }

        return (FleetMission::Attack === $fleet->getMission() && !$fleet->isReturning()) || $fleet->getCargo()->total() >= 1;
    }

    /** Own fleets the radar lists for their owner: attacking ones and those carrying resources. */
    public static function showsOwn(Fleet $fleet): bool
    {
        return self::emits($fleet)
            && ((FleetMission::Attack === $fleet->getMission() && !$fleet->isReturning()) || $fleet->getCargo()->total() >= 1);
    }

    /**
     * Pirates may raid fleets carrying resources, trade fleets flying back from a
     * transport and pickups both ways, even empty: there is no loot then, but a bomber
     * can destroy the ships.
     */
    public static function isRaidable(Fleet $fleet): bool
    {
        return $fleet->isMoving() && ($fleet->getCargo()->total() >= 1 || self::isReturningTrader($fleet) || FleetMission::Pickup === $fleet->getMission());
    }

    private static function isReturningTrader(Fleet $fleet): bool
    {
        return $fleet->isReturning() && \in_array($fleet->getMission(), [FleetMission::Transport, FleetMission::Trade, FleetMission::Supply, FleetMission::Pickup], true);
    }

    /** Coordinates of the next place the fleet will reach. @return array{int, int, int} */
    public static function nextStop(Fleet $fleet): array
    {
        return $fleet->isReturning()
            ? [$fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition()]
            : [$fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition()];
    }

    /**
     * Whether the fleet is on the player's radar – that depends on his radar research only,
     * also for attacks on his own planets (those he learns of on the overview, see knows()).
     */
    /** Own attacking or resource-carrying fleet that is within the own radar range right now. */
    public function showsOwnInRange(User $player, Fleet $fleet): bool
    {
        [$galaxy, $system] = $this->position($fleet);

        return self::showsOwn($fleet) && $this->covers($player, $galaxy, $system);
    }

    public function sees(User $player, Fleet $fleet): bool
    {
        if ($fleet->getOwner()->isSameAs($player)) {
            return true;
        }
        // Without radar research the radar shows nothing at all.
        if (null === self::range($player->getResearchLevel(ResearchType::Radar))) {
            return false;
        }
        if (FleetMission::Spy === $fleet->getMission()) {
            // Probes are silent; only top espionage notices those heading for the own planets.
            [$g, $s, $p] = self::nextStop($fleet);

            return $this->espionage->seesProbe($player, $fleet, $this->planets->findAt($g, $s, $p));
        }
        if (!self::emits($fleet)) {
            return false;
        }
        // Only while the fleet itself is within range – not as soon as it heads there.
        [$galaxy, $system] = $this->position($fleet);

        return $this->covers($player, $galaxy, $system);
    }

    /** Whether the fleet attacks one of the player's planets (it is aimed at it, flying there). */
    public function attacks(User $player, Fleet $fleet): bool
    {
        if (FleetMission::Attack !== $fleet->getMission() || $fleet->isReturning()) {
            return false;
        }
        [$g, $s, $p] = self::nextStop($fleet);

        return true === $this->planets->findAt($g, $s, $p)?->getOwner()->isSameAs($player);
    }

    /**
     * Whether the player knows of the fleet: an attack on his own planets he is told of at
     * once (overview, alarm), radar or not – everything else only his radar shows.
     */
    public function knows(User $player, Fleet $fleet): bool
    {
        return $this->attacks($player, $fleet) || $this->sees($player, $fleet);
    }

    /**
     * Attacks on the player's planets, earliest impact first – with or without radar.
     *
     * @return list<Fleet>
     */
    public function incomingAttacks(User $player): array
    {
        return array_values(array_filter(
            $this->fleets->findIncoming($player),
            fn (Fleet $fleet): bool => $this->attacks($player, $fleet),
        ));
    }

    /** @return list<Fleet> foreign fleets on the player's radar */
    public function visibleFleets(User $player): array
    {
        return array_values(array_filter(
            $this->fleets->findMovingOfOthers($player),
            fn (Fleet $fleet): bool => $this->sees($player, $fleet),
        ));
    }
}
