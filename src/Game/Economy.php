<?php

namespace App\Game;

use App\Entity\Planet;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Balancing formulas: production, population, raid protection, costs and build times.
 *
 * All rates are per hour and already include the game speed.
 */
final readonly class Economy
{
    /** Credits each inhabitant earns per hour (before game speed). */
    public const float CREDITS_PER_INHABITANT = 1.0;
    /** Credit price of a ship: one credit per this many resource units of its base cost. */
    public const int RESOURCES_PER_CREDIT = 20;

    /** Food and water each inhabitant consumes per hour (before game speed). */
    public const float FOOD_PER_INHABITANT = 0.05;
    public const float WATER_PER_INHABITANT = 0.05;
    /** Share of the bio cell capacity the population grows per hour. */
    public const float GROWTH_RATE = 0.05;
    /** Share of the population lost per hour while starving. */
    public const float STARVATION_RATE = 0.10;

    public function __construct(
        #[Autowire('%env(float:GAME_SPEED)%')]
        private float $speed,
    ) {
    }

    public function speed(): float
    {
        return $this->speed;
    }

    public function productionPerHour(Planet $planet): Resources
    {
        $buildingOutput = [];
        foreach (BuildingType::cases() as $type) {
            if (null !== $resource = $type->produces()) {
                $level = $planet->getBuildingLevel($type);
                $buildingOutput[$resource->value] = ($buildingOutput[$resource->value] ?? 0)
                    + $type->productionBase() * $level * 1.12 ** $level;
            }
        }

        $factor = $this->speed * $planet->getOwner()->getLifeForm()->productionFactor();

        return Resources::fromCallback(fn (ResourceType $resource): float => (
            $resource->baseProduction() + ($buildingOutput[$resource->value] ?? 0)
        ) * $factor);
    }

    /** Maximum population, limited by the bio cell. */
    public function populationCapacity(Planet $planet): float
    {
        $level = $planet->getBuildingLevel(BuildingType::BioCell);

        return 0 === $level ? 0.0 : floor(250 * 1.5 ** ($level - 1));
    }

    /** Inhabitants added per hour while food and water are available and capacity is left. */
    public function populationGrowthPerHour(Planet $planet): float
    {
        return self::GROWTH_RATE * $this->populationCapacity($planet) * $this->speed;
    }

    /** Food and water the given population consumes per hour. */
    public function consumptionPerHour(float $population): Resources
    {
        return new Resources(
            food: $population * self::FOOD_PER_INHABITANT * $this->speed,
            water: $population * self::WATER_PER_INHABITANT * $this->speed,
        );
    }

    public function creditsPerHour(float $population): float
    {
        return $population * self::CREDITS_PER_INHABITANT * $this->speed;
    }

    /** Resource cost of one ship for this player (life form modifier applied). */
    public function shipCost(ShipType $type, User $player): Resources
    {
        return $type->spec()->cost->multiply($player->getLifeForm()->shipResourceFactor())->ceil();
    }

    /** Credit cost of one ship for this player (settlers build ships without credits). */
    public function shipCredits(ShipType $type, User $player): float
    {
        return ceil($type->spec()->cost->total() / self::RESOURCES_PER_CREDIT * $player->getLifeForm()->shipCreditFactor());
    }

    /** @return list<string> reasons why a player can't build a ship (requirements and life form) */
    public function missingShipRequirements(ShipType $type, Planet $planet): array
    {
        $missing = $this->missingRequirements($type->spec()->requirements, $planet);
        $builder = $type->exclusiveBuilder();
        if (null !== $builder && $planet->getOwner()->getLifeForm() !== $builder) {
            array_unshift($missing, \sprintf('Nur %s können %s bauen', $builder->label(), $type->label()));
        }

        return $missing;
    }

    /** Inhabitants that are safe from bombardment. */
    public function bunkerCapacity(Planet $planet): float
    {
        $level = $planet->getBuildingLevel(BuildingType::Bunker);

        return 0 === $level ? 0.0 : floor(100 * 1.6 ** ($level - 1));
    }

    /**
     * Amount of each resource that is safe from being plundered in an attack.
     * Storage is unlimited; this only limits what attackers can take.
     */
    public function protectedAmount(Planet $planet): float
    {
        return floor(10000 * 1.5 ** $planet->getBuildingLevel(BuildingType::Storage));
    }

    public function buildCost(BuildingType $type, int $targetLevel): Resources
    {
        return $type->baseCost()->multiply($type->costFactor() ** ($targetLevel - 1))->floor();
    }

    public function buildSeconds(BuildingType $type, int $targetLevel, Planet $planet): int
    {
        $cost = $this->buildCost($type, $targetLevel);
        $hours = ($cost->iron + $cost->titanium) / (2500 * (1 + $planet->getBuildingLevel(BuildingType::CommandCenter)));

        return max(1, (int) round($hours * 3600 / $this->speed));
    }

    public function researchCost(ResearchType $type, int $targetLevel): Resources
    {
        return $type->baseCost()->multiply(2 ** ($targetLevel - 1))->floor();
    }

    /** Research time depends on the research lab of the planet it is started on. */
    public function researchSeconds(ResearchType $type, int $targetLevel, Planet $planet): int
    {
        $cost = $this->researchCost($type, $targetLevel);
        $hours = ($cost->iron + $cost->titanium) / (1000 * (1 + $planet->getBuildingLevel(BuildingType::ResearchLab)));

        return max(1, (int) round($hours * 3600 / $this->speed));
    }

    /** Build time of a single ship, depending on the shipyard level. */
    public function shipSeconds(ShipType $type, Planet $planet): int
    {
        $cost = $type->spec()->cost;
        $hours = ($cost->iron + $cost->titanium) / (2500 * (1 + $planet->getBuildingLevel(BuildingType::Shipyard)));

        return max(1, (int) round($hours * 3600 / $this->speed));
    }

    /**
     * Speed of a ship type including the bonus of its drive research (+10 % per level).
     *
     * Fixed order regardless of research: the fusionator is always faster than every
     * regular ship, and only the scout is faster than the fusionator.
     */
    public function shipSpeed(ShipType $type, User $player): int
    {
        return match ($type) {
            ShipType::Scout => max($this->researchedSpeed($type, $player), (int) ceil(1.1 * $this->shipSpeed(ShipType::Fusionator, $player))),
            ShipType::Fusionator => max($this->researchedSpeed($type, $player), (int) ceil(1.1 * $this->fastestRegularSpeed($player))),
            default => $this->researchedSpeed($type, $player),
        };
    }

    private function researchedSpeed(ShipType $type, User $player): int
    {
        $spec = $type->spec();

        return (int) round($spec->speed * (1 + 0.1 * $player->getResearchLevel($spec->drive)) * $player->getLifeForm()->speedFactor());
    }

    private function fastestRegularSpeed(User $player): int
    {
        $fastest = 0;
        foreach (ShipType::cases() as $type) {
            if (ShipType::Scout !== $type && ShipType::Fusionator !== $type && !$type->isStationary()) {
                $fastest = max($fastest, $this->researchedSpeed($type, $player));
            }
        }

        return $fastest;
    }

    /** How many planets one fusionator can weld together. */
    public function fusionCapacity(User $player): int
    {
        return min(50, $player->getResearchLevel(ResearchType::FusionTech));
    }

    /**
     * @param list<Requirement> $requirements
     *
     * @param bool $planned count building upgrades that are already queued
     *
     * @return list<string> human readable unmet requirements
     */
    public function missingRequirements(array $requirements, Planet $planet, bool $planned = false): array
    {
        $missing = [];
        foreach ($requirements as $requirement) {
            $current = match (true) {
                !$requirement->subject instanceof BuildingType => $planet->getOwner()->getResearchLevel($requirement->subject),
                $planned => $planet->getPlannedBuildingLevel($requirement->subject),
                default => $planet->getBuildingLevel($requirement->subject),
            };
            if ($current < $requirement->level) {
                $missing[] = $requirement->label();
            }
        }

        return $missing;
    }
}
