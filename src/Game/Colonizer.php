<?php

namespace App\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Repository\PlanetRepository;
use App\Repository\TradePostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

use function Symfony\Component\Translation\t;

/**
 * Places new players in the universe.
 */
final readonly class Colonizer
{
    public function __construct(
        private PlanetRepository $planets,
        private TradePostRepository $tradePosts,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Economy $economy,
    ) {
    }

    /**
     * Upgrades every new player can afford right away: five levels of the command
     * center, two levels each of farm and water pump to feed the population, and
     * the first shipyard level.
     *
     * @return list<array{BuildingType, int}> building => target level
     */
    public static function starterBuilds(): array
    {
        return [
            [BuildingType::CommandCenter, 2], [BuildingType::CommandCenter, 3], [BuildingType::CommandCenter, 4],
            [BuildingType::CommandCenter, 5], [BuildingType::CommandCenter, 6],
            [BuildingType::Farm, 1], [BuildingType::Farm, 2],
            [BuildingType::WaterPump, 1], [BuildingType::WaterPump, 2],
            [BuildingType::Shipyard, 1],
        ];
    }

    /**
     * Start resources: enough for all starter builds plus a small reserve of food
     * and water, rounded up to full 500.
     */
    public function startResources(): Resources
    {
        $needed = new Resources(food: 200, water: 200, hydrogen: 100);
        foreach (self::starterBuilds() as [$type, $level]) {
            $needed = $needed->add($this->economy->buildCost($type, $level));
        }

        return Resources::fromCallback(static fn (ResourceType $t): float => ceil($needed->get($t) / 500) * 500);
    }

    /**
     * Creates (and persists, without flushing) the starting planet of a new player.
     */
    public function createHomePlanet(User $user): Planet
    {
        [$galaxy, $system, $position] = $this->findFreeSlot();

        $planet = new Planet($user, 'Heimatwelt', $galaxy, $system, $position, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $planet->setResources($this->startResources());
        $planet->setBuildingLevel(BuildingType::CommandCenter, 1);
        $planet->setBuildingLevel(BuildingType::BioCell, 1);
        $planet->setPopulation(100);
        $planet->markHomeworld();
        $user->addCredits(1000);
        $this->em->persist($planet);

        return $planet;
    }

    /**
     * Founds a colony on a free slot (persisted, not flushed). The settlers need
     * a home, so every colony starts with a bio cell.
     */
    public function foundColony(User $user, int $galaxy, int $system, int $position, float $settlers, Resources $cargo): Planet
    {
        $name = PlanetNames::random(array_map(static fn (Planet $p): string => $p->getName(), $user->getPlanets()->toArray()));
        $planet = new Planet($user, $name, $galaxy, $system, $position, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $planet->setResources($cargo);
        $planet->setBuildingLevel(BuildingType::BioCell, 1);
        $planet->setPopulation($settlers);
        $this->em->persist($planet);

        return $planet;
    }

    public function isValidSlot(int $galaxy, int $system, int $position): bool
    {
        return Universe::isValidSlot($galaxy, $system, $position);
    }

    /** @return array{int, int, int} */
    private function findFreeSlot(): array
    {
        for ($attempt = 0; $attempt < 200; ++$attempt) {
            $galaxy = random_int(1, Universe::GALAXIES);
            $system = random_int(1, Universe::SYSTEMS_PER_GALAXY);
            $slot = [$galaxy, $system, random_int(1, Universe::planetsInSystem($galaxy, $system))];
            // Nobody starts on a stone or desert world.
            if (!Universe::planetType(...$slot)->isFriendly()) {
                continue;
            }
            if (!$this->planets->isOccupied(...$slot) && null === $this->tradePosts->findAt(...$slot)) {
                return $slot;
            }
        }

        throw new GameException(t('Das Universum ist voll. Bitte wende dich an die Spielleitung.'));
    }
}
