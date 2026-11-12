<?php

namespace App\Game;

use App\Entity\Planet;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function Symfony\Component\Translation\t;

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
        /** Extra factor for research only, on top of the game speed (1 = no extra). */
        #[Autowire('%env(float:RESEARCH_SPEED)%')]
        private float $researchSpeed = 1.0,
        /** Whether new players get the starter bonus (on in the game, off in most unit tests). */
        #[Autowire(param: 'game.starter_bonus')]
        private bool $starterBonus = false,
    ) {
    }

    /** New players: during their first day everything builds and produces twice as fast. */
    public const int STARTER_BONUS_HOURS = 24;
    public const float STARTER_BONUS_FACTOR = 2.0;

    /** End of the player's starter bonus, null if there is none. */
    public function starterBonusUntil(User $player): ?\DateTimeImmutable
    {
        return $this->starterBonus ? $player->getCreatedAt()->modify('+'.self::STARTER_BONUS_HOURS.' hours') : null;
    }

    public function hasStarterBonus(User $player, ?\DateTimeImmutable $at): bool
    {
        $until = $this->starterBonusUntil($player);

        return null !== $at && null !== $until && $at >= $player->getCreatedAt() && $at < $until;
    }

    /** Speed factor for work started at $at (2 during the starter bonus, else 1; null = no bonus). */
    public function starterFactor(User $player, ?\DateTimeImmutable $at): float
    {
        return $this->hasStarterBonus($player, $at) ? self::STARTER_BONUS_FACTOR : 1.0;
    }

    /** Average factor over a stretch of time that may begin or end inside the bonus. */
    public function starterFactorBetween(User $player, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $until = $this->starterBonusUntil($player);
        $length = $to->getTimestamp() - $from->getTimestamp();
        if (null === $until || $length <= 0) {
            return $this->starterFactor($player, $from);
        }
        $inside = min($to->getTimestamp(), $until->getTimestamp()) - max($from->getTimestamp(), $player->getCreatedAt()->getTimestamp());

        return 1.0 + (self::STARTER_BONUS_FACTOR - 1.0) * max(0, $inside) / $length;
    }

    public function researchSpeed(): float
    {
        return $this->researchSpeed;
    }

    public function speed(): float
    {
        return $this->speed;
    }

    public const float FUSION_PRODUCTION_FACTOR = 1.5;

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

        // Fusions are more lucrative than single planets: every resource ×1.5.
        $factor = $this->speed * $planet->getOwner()->getLifeForm()->productionFactor() * ($planet->isFused() ? self::FUSION_PRODUCTION_FACTOR : 1.0);
        $type = $planet->getType();

        return Resources::fromCallback(fn (ResourceType $resource): float => (
            $resource->baseProduction() + ($buildingOutput[$resource->value] ?? 0)
        ) * $factor * $type->productionFactor($resource));
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
        return self::GROWTH_RATE * $this->populationCapacity($planet) * $this->speed * $planet->getType()->growthFactor();
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

    /** Build rates for ships and defenses: more resources, shorter build time (resources stay safe in the queue). */
    public const array BUILD_PERCENTS = [100, 125, 150, 175, 200];

    public static function assertBuildPercent(int $percent): void
    {
        if (!\in_array($percent, self::BUILD_PERCENTS, true)) {
            throw new GameException(t('Gebaut werden kann nur mit 100, 125, 150, 175 oder 200 %.'));
        }
    }

    /**
     * Resources for one ship built at a rate above 100 %: the normal cost times the rate,
     * plus as much food and water as the extra iron, and a tenth of that as hydrogen.
     * Paid into the queue, all of it is safe from plundering until the ship is built.
     */
    public function shipOrderCost(ShipType $type, User $player, int $percent): Resources
    {
        $base = $this->shipCost($type, $player);
        $extraIron = $base->iron * ($percent - 100) / 100;

        return $base->multiply($percent / 100)
            ->add(new Resources(food: $extraIron, water: $extraIron, hydrogen: $extraIron / 10))
            ->ceil();
    }

    /** Credits per unit: unchanged for ships, multiplied by the rate for defenses. */
    public function shipOrderCredits(ShipType $type, User $player, int $percent): float
    {
        $credits = $this->shipCredits($type, $player);

        return $type->isStationary() ? ceil($credits * $percent / 100) : $credits;
    }

    /** Build time per unit shrinks with the rate: 200 % = half the time. */
    public function shipOrderSeconds(ShipType $type, Planet $planet, int $percent, ?\DateTimeImmutable $at = null): int
    {
        return max(1, (int) round($this->shipSeconds($type, $planet, $at) * 100 / $percent));
    }

    /** Credit cost of one ship for this player (settlers build ships without credits). */
    public function shipCredits(ShipType $type, User $player): float
    {
        return ceil($type->spec()->cost->total() / self::RESOURCES_PER_CREDIT * $player->getLifeForm()->shipCreditFactor());
    }

    /** @return list<\Symfony\Contracts\Translation\TranslatableInterface> reasons why a player can't build a ship (requirements and life form) */
    public function missingShipRequirements(ShipType $type, Planet $planet): array
    {
        $missing = $this->missingRequirements($type->spec()->requirements, $planet);
        $builder = $type->exclusiveBuilder();
        if (null !== $builder && $planet->getOwner()->getLifeForm() !== $builder) {
            array_unshift($missing, t('Nur {builder} können {type} bauen', ['{builder}' => t($builder->label()), '{type}' => t($type->label())]));
        }

        return $missing;
    }

    /** Inhabitants that are safe from bombardment. */
    /** Planetary shield per level of the shield generator (levels add up in a fusion). */
    public const int SHIELD_PER_LEVEL = 20_000;

    public function planetShield(Planet $planet): int
    {
        return self::SHIELD_PER_LEVEL * $planet->getBuildingLevel(BuildingType::ShieldGenerator);
    }

    /** One invasion ship is enough for about this many inhabitants. */
    public const int INHABITANTS_PER_INVADER = 20_000;
    public const float INVASION_MIN_CHANCE = 0.01;
    public const float INVASION_MAX_CHANCE = 0.99;

    /**
     * Chance that an invasion succeeds: 90 % with exactly enough invasion ships,
     * linear in their number, never below 1 % and never above 99 %.
     */
    public static function invasionChance(int $invaders, float $population): float
    {
        $ratio = $invaders * self::INHABITANTS_PER_INVADER / max(1.0, $population);

        return max(self::INVASION_MIN_CHANCE, min(self::INVASION_MAX_CHANCE, 0.9 * $ratio));
    }

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

    /** Surcharge for a building upgrade queued behind one that is already waiting. */
    public const float SECOND_BUILD_SURCHARGE = 0.3;

    public function buildCost(BuildingType $type, int $targetLevel): Resources
    {
        return $type->baseCost()->multiply($type->costFactor() ** ($targetLevel - 1))->floor();
    }

    /** What queuing an upgrade costs: the second entry of the building queue costs 30 % more. */
    public function queuedBuildCost(BuildingType $type, int $targetLevel, int $alreadyQueued): Resources
    {
        $cost = $this->buildCost($type, $targetLevel);

        return $alreadyQueued > 0 ? $cost->multiply(1 + self::SECOND_BUILD_SURCHARGE)->ceil() : $cost;
    }

    /** Build time of an upgrade started at $at (the starter bonus halves it). */
    public function buildSeconds(BuildingType $type, int $targetLevel, Planet $planet, ?\DateTimeImmutable $at = null): int
    {
        $cost = $this->buildCost($type, $targetLevel);
        $hours = ($cost->iron + $cost->titanium) / (2500 * (1 + $planet->getBuildingLevel(BuildingType::CommandCenter)));

        return max(1, (int) round($hours * 3600 / $this->speed / $this->starterFactor($planet->getOwner(), $at)));
    }

    public function researchCost(ResearchType $type, int $targetLevel): Resources
    {
        $factor = match (true) {
            // Colonization: 10 times the long-term step cost of its level (no ceiling, so it must stay payable).
            ResearchType::Colonization === $type => 10 * $targetLevel ** 1.5,
            $type->isLongTerm() => array_sum(array_map(static fn (int $step): float => $step ** 1.5, self::longTermSteps($type, $targetLevel))),
            default => 2 ** ($targetLevel - 1),
        };

        return $type->baseCost()->multiply($factor)->floor();
    }

    /**
     * Lab hours of the top level of a long-term research: about two months for a player
     * who fuses 49 planets with research labs at level 20 to research it.
     */
    public const int LONG_RESEARCH_LAB_HOURS = 1_412_640;
    /** Steepness of the long-term curve: step n needs LONG_RESEARCH_LAB_HOURS × (n/50)^2.6 lab hours. */
    public const float LONG_RESEARCH_EXPONENT = 2.6;
    public const int LONG_RESEARCH_STEPS = 50;

    /**
     * Colonization (10 planet slots per level, no upper limit): the model player – whose
     * research planet grows to a fusion of 49 planets with labs at level 20 by level 50 –
     * needs 1 day for level 1, 14 days for level 2 and 14 × √(level/2) days after that
     * (level 50: 70 days). All 50 levels (500 slots) take him about 6 years; together with
     * fusion research in the same slot about 10 years. Fewer labs take proportionally longer.
     */
    public static function colonizationLabHours(int $level): float
    {
        $days = 1 === $level ? 1.0 : 14 * sqrt($level / 2);

        return $days * 24 * (1 + self::colonizationModelLabs($level));
    }

    /** Lab levels of the model player's research planet at a colonization level: 4 at first, 980 from level 50. */
    public static function colonizationModelLabs(int $level): float
    {
        return 4 + 976 * min(1.0, max(0, $level - 1) / 49);
    }

    /**
     * The steps of the long-term curve a level stands for (colonization level 2 = steps 11–20).
     *
     * @return list<int>
     */
    private static function longTermSteps(ResearchType $type, int $targetLevel): array
    {
        $per = $type->stepsPerLevel();

        return range(($targetLevel - 1) * $per + 1, $targetLevel * $per);
    }

    /**
     * Seconds after the start during which a fleet can be recalled (Kommunikationstechnik):
     * none without it, 5 s at level 1, 5 minutes at level 20, growing further.
     */
    public static function recallWindow(User $player): int
    {
        $level = $player->getResearchLevel(ResearchType::RadarTech);

        return 0 === $level ? 0 : (int) round(5 * 60 ** (($level - 1) / 19));
    }

    /** Research time depends on the research lab of the planet it is started on (and the starter bonus at $at). */
    public function researchSeconds(ResearchType $type, int $targetLevel, Planet $planet, ?\DateTimeImmutable $at = null): int
    {
        $labs = 1 + $planet->getBuildingLevel(BuildingType::ResearchLab);
        if (ResearchType::Colonization === $type) {
            $labHours = self::colonizationLabHours($targetLevel);
        } elseif ($type->isLongTerm()) {
            $labHours = self::LONG_RESEARCH_LAB_HOURS * ($targetLevel / self::LONG_RESEARCH_STEPS) ** self::LONG_RESEARCH_EXPONENT;
        } else {
            $cost = $this->researchCost($type, $targetLevel);
            $labHours = ($cost->iron + $cost->titanium) / 1000;
        }

        return max(1, (int) round($labHours / $labs * 3600 / ($this->speed * $this->researchSpeed * $this->starterFactor($planet->getOwner(), $at))));
    }

    /** Build time of a single ship, depending on the shipyard level (and the starter bonus at $at). */
    public function shipSeconds(ShipType $type, Planet $planet, ?\DateTimeImmutable $at = null): int
    {
        $hours = self::baseShipHours($type) / (1 + $planet->getBuildingLevel(BuildingType::Shipyard));

        return max(1, (int) round($hours * 3600 / $this->speed / $this->starterFactor($planet->getOwner(), $at)));
    }

    /** Build hours of one unit in a shipyard of level 0 at normal game speed: its weight in a battle. */
    public static function baseShipHours(ShipType $type): float
    {
        $cost = $type->spec()->cost;

        return ($cost->iron + $cost->titanium) / 2500;
    }

    /**
     * Speed of a ship type including the bonus of its drive research (+4 % per level).
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

        return (int) round($spec->speed * (1 + Battle::RESEARCH_BONUS * $player->getResearchLevel($spec->drive)) * $player->getLifeForm()->speedFactor());
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
    public const int SLOTS_PER_COLONIZATION_LEVEL = 10;

    /** Planet slots a player may hold: only the home planet without research, then 10 per level (welded slots count one by one). */
    /** How many fleets a player can command: one per level of fleet control center on all his planets. */
    public static function fleetCapacity(User $player): int
    {
        $levels = 0;
        foreach ($player->getPlanets() as $planet) {
            $levels += $planet->getBuildingLevel(BuildingType::FleetControl);
        }

        return $levels;
    }

    public function colonySlots(User $player): int
    {
        return max(1, self::SLOTS_PER_COLONIZATION_LEVEL * $player->getResearchLevel(ResearchType::Colonization));
    }

    public static function ownedSlots(User $player, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        return array_sum(array_map(static fn (Planet $p): int => $p->slotCost($now), $player->getPlanets()->toArray()));
    }

    /** Largest fusion allowed (and planets one fusionator welds): the fusion research level, at most 49. */
    public function fusionCapacity(User $player): int
    {
        return min(ResearchType::FUSION_MAX_LEVEL, $player->getResearchLevel(ResearchType::FusionTech));
    }

    /**
     * @param list<Requirement> $requirements
     *
     * @param bool $planned count building upgrades that are already queued
     *
     * @return list<\Symfony\Contracts\Translation\TranslatableInterface> human readable unmet requirements
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
