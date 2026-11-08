<?php

namespace App\Game;

use App\Entity\Fleet;
use App\Entity\FleetIntel;
use App\Entity\Planet;
use App\Entity\PlanetIntel;
use App\Entity\User;
use App\Repository\FleetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Probes and what they reveal. The spy's level of espionage research decides
 * the depth of a report: 1 planet type, 5 buildings/resources/population/research,
 * 10 stationed ships, 20 seeing probes that head for the own planets.
 */
final readonly class Espionage
{
    public const int LEVEL_TYPE = 1;
    public const int LEVEL_BUILDINGS = 5;
    public const int LEVEL_SHIPS = 10;
    public const int LEVEL_SEE_PROBES = 20;

    public function __construct(
        private EntityManagerInterface $em,
        private FleetRepository $fleets,
        private ClockInterface $clock,
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

    /** Remembers the type of a scouted slot (persisted, flushed by the caller). */
    public function remember(User $player, int $galaxy, int $system, int $position): PlanetType
    {
        $type = Universe::planetType($galaxy, $system, $position);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $intel = $this->em->getRepository(PlanetIntel::class)->findOneBy(['user' => $player, 'galaxy' => $galaxy, 'system' => $system, 'position' => $position]);
        if (null === $intel) {
            $this->em->persist(new PlanetIntel($player, $galaxy, $system, $position, $type, $now));
        } else {
            $intel->refresh($now);
        }

        return $type;
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

    /** Whether a player notices a probe heading for one of his planets. */
    public function seesProbe(User $player, Fleet $probe, ?Planet $target): bool
    {
        return FleetMission::Spy === $probe->getMission() && !$probe->isReturning()
            && null !== $target && $target->getOwner()->isSameAs($player)
            && $player->getResearchLevel(ResearchType::Espionage) >= self::LEVEL_SEE_PROBES;
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
