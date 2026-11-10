<?php

namespace App\Game;

use App\Entity\DebrisField;
use App\Entity\Fleet;
use App\Entity\FleetIntel;
use App\Entity\Planet;
use App\Entity\PlanetIntel;
use App\Entity\User;
use App\Repository\DebrisFieldRepository;
use App\Repository\FleetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Probes and what they reveal. The spy's level of espionage research decides
 * the depth of a report (1 planet type, 5 buildings/resources/population/research,
 * 10 stationed ships) and the probes' speed. The victim's level decides what he
 * learns of being spied on: 25 that it happened, 40 who it was, 50 probes in flight.
 */
final readonly class Espionage
{
    public const int LEVEL_TYPE = 1;
    public const int LEVEL_BUILDINGS = 5;
    public const int LEVEL_SHIPS = 10;
    public const int LEVEL_NOTICE_SPY = 25;
    public const int LEVEL_NAME_SPY = 40;
    public const int LEVEL_SEE_PROBES = 50;

    public function __construct(
        private EntityManagerInterface $em,
        private FleetRepository $fleets,
        private ClockInterface $clock,
        private ?DebrisFieldRepository $debris = null,
    ) {
    }

    /**
     * Planet types the player knows in a system: own planets plus scouted slots.
     *
     * @return array<int, PlanetType> position => type
     */
    public function knownTypes(User $player, int $galaxy, int $system): array
    {
        $known = [];
        foreach ($this->em->getRepository(PlanetIntel::class)->findBy(['user' => $player, 'galaxy' => $galaxy, 'system' => $system]) as $intel) {
            $known[$intel->getPosition()] = $intel->getType();
        }
        foreach ($player->getPlanets() as $planet) {
            if ($planet->getGalaxy() === $galaxy && $planet->getSystem() === $system) {
                for ($position = $planet->getPosition(); $position <= $planet->getLastPosition(); ++$position) {
                    $known[$position] = Universe::planetType($galaxy, $system, $position);
                }
                $known[$planet->getPosition()] = $planet->getType();
            }
        }

        return $known;
    }

    /** Remembers the type of a scouted slot and its debris (persisted, flushed by the caller). */
    public function remember(User $player, int $galaxy, int $system, int $position): PlanetType
    {
        $intel = $this->intel($player, $galaxy, $system, $position);
        $intel->refresh(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->applyDebris($intel, $galaxy, $system, $position);

        return $intel->getType();
    }

    /** The player sees the debris field at a slot as it is now (probe, battle, salvaging). */
    public function noteDebris(User $player, int $galaxy, int $system, int $position): void
    {
        $this->applyDebris($this->intel($player, $galaxy, $system, $position), $galaxy, $system, $position);
    }

    private function applyDebris(PlanetIntel $intel, int $galaxy, int $system, int $position): void
    {
        $field = $this->debrisAt($galaxy, $system, $position);
        $intel->seeDebris($field?->getIron(), $field?->getTitanium(), \DateTimeImmutable::createFromInterface($this->clock->now()));
    }

    /**
     * Debris fields the player knows about in a system and that still drift there.
     *
     * @return array<int, PlanetIntel> position => intel with the amounts seen
     */
    public function knownDebris(User $player, int $galaxy, int $system): array
    {
        $existing = [];
        foreach ($this->debris?->findInSystem($galaxy, $system) ?? [] as $position => $field) {
            $existing[$field->getPosition()] = true;
        }
        $known = [];
        foreach ($this->em->getRepository(PlanetIntel::class)->findBy(['user' => $player, 'galaxy' => $galaxy, 'system' => $system]) as $intel) {
            if ($intel->hasDebris() && isset($existing[$intel->getPosition()])) {
                $known[$intel->getPosition()] = $intel;
            }
        }

        return $known;
    }

    private function intel(User $player, int $galaxy, int $system, int $position): PlanetIntel
    {
        $repository = $this->em->getRepository(PlanetIntel::class);
        $intel = $repository->findOneBy(['user' => $player, 'galaxy' => $galaxy, 'system' => $system, 'position' => $position]);
        if (null === $intel) {
            // Created earlier in this request but not flushed yet?
            foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                if ($entity instanceof PlanetIntel && $entity->isAt($player, $galaxy, $system, $position)) {
                    return $entity;
                }
            }
            $intel = new PlanetIntel($player, $galaxy, $system, $position, Universe::planetType($galaxy, $system, $position), \DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->em->persist($intel);
        }

        return $intel;
    }

    /** The debris field at a slot, also one just created in this request (not flushed yet). */
    private function debrisAt(int $galaxy, int $system, int $position): ?DebrisField
    {
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof DebrisField && $entity->getGalaxy() === $galaxy && $entity->getSystem() === $system && $entity->getPosition() === $position) {
                return $entity;
            }
        }

        return $this->debris?->findAt($galaxy, $system, $position);
    }

    /** Debris line of a spy report. */
    private function debrisLine(int $galaxy, int $system, int $position): string
    {
        $field = $this->debrisAt($galaxy, $system, $position);

        return null === $field || $field->isEmpty()
            ? 'Trümmerfeld: keins'
            : \sprintf('Trümmerfeld: %s Eisen, %s Titan – mit Bergungsschiffen abholen', number_format($field->getIron(), 0, ',', '.'), number_format($field->getTitanium(), 0, ',', '.'));
    }

    /** A spy report as deep as the spy's espionage research allows. */
    public function report(User $spy, int $galaxy, int $system, int $position, ?Planet $planet): string
    {
        $level = $spy->getResearchLevel(ResearchType::Espionage);
        $slotType = $this->remember($spy, $galaxy, $system, $position);
        $type = $planet?->getType() ?? $slotType;
        if (null !== $planet && $planet->getPosition() !== $position) {
            $this->remember($spy, $galaxy, $system, $planet->getPosition());
        }

        $lines = [
            null === $planet
                ? \sprintf('Freier Platz [%d:%d:%d]', $galaxy, $system, $position)
                : \sprintf('Planet %s [%s] von %s', $planet->getName(), $planet->getCoordinates(), $planet->getOwner()->getUsername()),
            \sprintf('Planetentyp: %s %s (%s)', $type->icon(), $type->label(), $type->effect()),
            $this->debrisLine($galaxy, $system, $position),
        ];
        if (null === $planet) {
            return implode("\n", $lines);
        }

        if ($level < self::LEVEL_BUILDINGS) {
            $lines[] = '';
            $lines[] = \sprintf('Ab Spionagetechnik Stufe %d erfährst du Gebäude, Rohstoffe und Bevölkerung.', self::LEVEL_BUILDINGS);

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = 'Rohstoffe:';
        foreach (ResourceType::cases() as $resource) {
            $lines[] = \sprintf('  %s: %s', $resource->label(), number_format($planet->getResources()->get($resource), 0, ',', '.'));
        }
        $lines[] = '';
        $lines[] = 'Bevölkerung: '.number_format($planet->getPopulation(), 0, ',', '.');
        $lines[] = '';
        $lines[] = 'Gebäude:';
        foreach (BuildingType::cases() as $building) {
            if ($planet->getBuildingLevel($building) > 0) {
                $lines[] = \sprintf('  %s: Stufe %d', $building->label(), $planet->getBuildingLevel($building));
            }
        }
        $lines[] = '';
        $lines[] = 'Forschung:';
        foreach (ResearchType::cases() as $research) {
            if ($planet->getOwner()->getResearchLevel($research) > 0) {
                $lines[] = \sprintf('  %s: Stufe %d', $research->label(), $planet->getOwner()->getResearchLevel($research));
            }
        }

        if ($level < self::LEVEL_SHIPS) {
            $lines[] = '';
            $lines[] = \sprintf('Ab Spionagetechnik Stufe %d erfährst du die stationierten Schiffe.', self::LEVEL_SHIPS);

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = 'Stationierte Schiffe und Türme:';
        $lines = [...$lines, ...$this->shipLines($planet->getShips(), '  ')];
        foreach ($this->fleets->findIdleAt($galaxy, $system, $position) as $fleet) {
            $lines[] = \sprintf('  Flotte von %s:', $fleet->getOwner()->getUsername());
            $lines = [...$lines, ...$this->shipLines($fleet->getShips(), '    ')];
        }

        return implode("\n", $lines);
    }

    /**
     * What the player's probes found out about these fleets.
     *
     * @param list<Fleet> $fleets
     *
     * @return array<int, FleetIntel> fleet id => intel
     */
    public function fleetIntel(User $player, array $fleets): array
    {
        if ([] === $fleets) {
            return [];
        }
        $intel = [];
        foreach ($this->em->getRepository(FleetIntel::class)->findBy(['user' => $player, 'fleet' => $fleets]) as $entry) {
            $intel[$entry->getFleet()->getId()] = $entry;
        }

        return $intel;
    }

    /** A probe met a foreign fleet: remember and report what it carries. */
    public function scoutFleet(User $player, Fleet $target): string
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $intel = $this->em->getRepository(FleetIntel::class)->findOneBy(['user' => $player, 'fleet' => $target]);
        if (null === $intel) {
            $this->em->persist(new FleetIntel($player, $target, $target->getShips(), $target->getCargo()->total(), $now));
        } else {
            $intel->update($target->getShips(), $target->getCargo()->total(), $now);
        }

        [$g, $s, $p] = Radar::nextStop($target);
        $lines = [
            \sprintf('Flotte von %s: %s nach [%d:%d:%d], Ankunft %s', $target->getOwner()->getUsername(), $target->getMission()?->label() ?? 'unterwegs', $g, $s, $p, $target->getNextEventAt()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i:s')),
            '',
            'Schiffe:',
            ...$this->shipLines($target->getShips(), '  '),
        ];
        if ($target->getCargo()->total() >= 1) {
            $lines[] = '';
            $lines[] = 'Ladung: '.number_format($target->getCargo()->total(), 0, ',', '.').' Rohstoffe';
        }

        return implode("\n", $lines);
    }

    /** Whether a player notices a probe heading for one of his planets or fleets. */
    public function seesProbe(User $player, Fleet $probe, ?Planet $target): bool
    {
        $victim = $probe->getTargetFleet()?->getOwner() ?? $target?->getOwner();

        return FleetMission::Spy === $probe->getMission() && !$probe->isReturning()
            && null !== $victim && $victim->isSameAs($player)
            && $player->getResearchLevel(ResearchType::Espionage) >= self::LEVEL_SEE_PROBES;
    }

    /**
     * What the spied-on player learns of a probe's visit: nothing below level 25,
     * that it happened from 25 on, and who sent it from 40 on.
     *
     * @return array{string, string}|null subject and text of the message
     */
    public function spiedNotice(User $victim, User $spy, string $what): ?array
    {
        $level = $victim->getResearchLevel(ResearchType::Espionage);
        if ($level < self::LEVEL_NOTICE_SPY) {
            return null;
        }
        if ($level < self::LEVEL_NAME_SPY) {
            return ['Ausspioniert', "$what wurde von einer Sonde ausspioniert. Wer sie geschickt hat, erfährst du ab Spionagetechnik Stufe ".self::LEVEL_NAME_SPY.'.'];
        }

        return ['Ausspioniert von '.$spy->getUsername(), "$what wurde von einer Sonde von {$spy->getUsername()} ausspioniert."];
    }

    /**
     * @param array<string, int> $ships
     *
     * @return list<string>
     */
    private function shipLines(array $ships, string $indent): array
    {
        $lines = [];
        foreach (ShipType::cases() as $type) {
            if (($ships[$type->value] ?? 0) > 0) {
                $lines[] = \sprintf('%s%s: %s', $indent, $type->label(), number_format($ships[$type->value], 0, ',', '.'));
            }
        }

        return [] === $lines ? [$indent.'keine'] : $lines;
    }
}
