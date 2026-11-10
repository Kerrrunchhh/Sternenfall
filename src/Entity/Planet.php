<?php

namespace App\Entity;

use App\Game\BuildingType;
use App\Game\PlanetType;
use App\Game\QueueKind;
use App\Game\Universe;
use App\Game\Resources;
use App\Game\ShipType;
use App\Repository\PlanetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanetRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_planet_coordinates', columns: ['galaxy', 'solar_system', 'position'])]
class Planet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'planets')]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    #[ORM\Column(length: 30)]
    private string $name;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $galaxy;

    #[ORM\Column(name: 'solar_system', type: Types::SMALLINT)]
    private int $system;

    /** Topmost position this planet occupies. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    /** The planet a player started on: it can be attacked, but never invaded. */
    #[ORM\Column(options: ['default' => false])]
    private bool $homeworld = false;

    /** Left out of the owner's public planet list (e.g. a combat colony); at most a few per player. */
    #[ORM\Column(options: ['default' => false])]
    private bool $hiddenInProfile = false;

    /** After an invasion the planet only fully passes to its new owner after this period. */
    public const string INTEGRATION_PERIOD = '+30 days';
    /** Until then it takes 3 planet slots, after 14 days 2 (in a war 2 from the start). */
    public const int INVADED_SLOTS = 3;
    public const int INVADED_SLOTS_LATER = 2;
    public const string INVADED_SLOTS_DROP = '+14 days';

    /** Until then the planet is invaded but not yet fully owned (e.g. it can't be fused). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $integratedAt = null;

    /** When the planet was invaded (null for invasions before the slot rule). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $invadedAt = null;

    /** Being abandoned: at this time the planet is given up (slot free again). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $abandonAt = null;

    /** Invaded in a war between the two alliances: it takes fewer slots. */
    #[ORM\Column(options: ['default' => false])]
    private bool $invadedInWar = false;

    /** Number of consecutive positions occupied: 1 for a normal planet, more after a fusion. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $fusionSize = 1;

    /** @var list<array{position: int, name: string}> planets welded into this one, to restore them when blown apart */
    #[ORM\Column(type: Types::JSON)]
    private array $fusedPlanets = [];

    #[ORM\Column]
    private float $iron = 0;

    #[ORM\Column]
    private float $titanium = 0;

    #[ORM\Column]
    private float $food = 0;

    #[ORM\Column]
    private float $water = 0;

    #[ORM\Column]
    private float $hydrogen = 0;

    #[ORM\Column]
    private float $population = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $resourcesUpdatedAt;

    /** @var array<string, int> building type value => level */
    #[ORM\Column(type: Types::JSON)]
    private array $buildings = [];

    /** @var array<string, int> ship type value => number of ships stationed here */
    #[ORM\Column(type: Types::JSON)]
    private array $ships = [];

    /** @var Collection<int, ShipyardOrder> */
    #[ORM\OneToMany(targetEntity: ShipyardOrder::class, mappedBy: 'planet', cascade: ['persist', 'refresh'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $shipyardOrders;

    /** @var Collection<int, BuildingOrder> */
    #[ORM\OneToMany(targetEntity: BuildingOrder::class, mappedBy: 'planet', cascade: ['persist', 'refresh'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $buildingOrders;

    public function __construct(User $owner, string $name, int $galaxy, int $system, int $position, \DateTimeImmutable $now)
    {
        $this->owner = $owner;
        $this->name = $name;
        $this->galaxy = $galaxy;
        $this->system = $system;
        $this->position = $position;
        $this->resourcesUpdatedAt = $now;
        $this->shipyardOrders = new ArrayCollection();
        $this->buildingOrders = new ArrayCollection();
        $owner->addPlanet($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getGalaxy(): int
    {
        return $this->galaxy;
    }

    public function getSystem(): int
    {
        return $this->system;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCoordinates(): string
    {
        return $this->isFused()
            ? \sprintf('%d:%d:%d-%d', $this->galaxy, $this->system, $this->position, $this->getLastPosition())
            : \sprintf('%d:%d:%d', $this->galaxy, $this->system, $this->position);
    }

    public function isHomeworld(): bool
    {
        return $this->homeworld;
    }

    public function markHomeworld(): void
    {
        $this->homeworld = true;
    }

    /**
     * The owner's account is deleted: the planet stays as an ownerless planet. Queued
     * orders are refunded into its storage; resources, ships and defenses stay on it,
     * the buildings only if they are not destroyed.
     */
    public function leaveToNobody(User $nobody, bool $destroyBuildings): void
    {
        foreach ($this->buildingOrders->toArray() as $order) {
            $this->setResources($this->getResources()->add($order->getCost()));
            $this->removeBuildingOrder($order);
        }
        foreach ($this->shipyardOrders->toArray() as $order) {
            $this->setResources($this->getResources()->add($order->getUnitCost()->multiply($order->getRemaining())));
            $this->removeShipyardOrder($order);
        }
        if ($destroyBuildings) {
            $this->buildings = [];
        }
        $this->owner->getPlanets()->removeElement($this);
        $this->owner = $nobody;
        $nobody->addPlanet($this);
        $this->homeworld = false;
        $this->hiddenInProfile = false;
        $this->abandonAt = null;
    }

    /** Marks the planet as freshly invaded: it becomes fully owned after the integration period. */
    public function markInvaded(\DateTimeImmutable $now, bool $inWar = false): void
    {
        $this->integratedAt = $now->modify(self::INTEGRATION_PERIOD);
        $this->invadedAt = $now;
        $this->invadedInWar = $inWar;
    }

    /** Slots an invaded planet takes while it is not fully owned: 3, after 14 days (or in a war) 2. */
    public static function invadedSlots(\DateTimeImmutable $invadedAt, bool $inWar, \DateTimeImmutable $now): int
    {
        return $inWar || $now >= $invadedAt->modify(self::INVADED_SLOTS_DROP) ? self::INVADED_SLOTS_LATER : self::INVADED_SLOTS;
    }

    /** Planet slots this planet takes now (welded slots count one by one; freshly invaded ones more). */
    public function slotCost(\DateTimeImmutable $now): int
    {
        if (null === $this->invadedAt || !$this->isIntegrating($now)) {
            return $this->fusionSize;
        }

        return $this->fusionSize - 1 + self::invadedSlots($this->invadedAt, $this->invadedInWar, $now);
    }

    /** After a vacation: production, queues and abandoning go on as if no time had passed. */
    public function shiftClock(int $seconds): void
    {
        $this->resourcesUpdatedAt = $this->resourcesUpdatedAt->modify("+{$seconds} seconds");
        $this->abandonAt = $this->abandonAt?->modify("+{$seconds} seconds");
        foreach ($this->buildingOrders as $order) {
            $order->shiftClock($seconds);
        }
        foreach ($this->shipyardOrders as $order) {
            $order->shiftClock($seconds);
        }
    }

    public function getAbandonAt(): ?\DateTimeImmutable
    {
        return $this->abandonAt;
    }

    public function setAbandonAt(?\DateTimeImmutable $at): void
    {
        $this->abandonAt = $at;
    }

    public function isBeingAbandoned(): bool
    {
        return null !== $this->abandonAt;
    }

    public function getInvadedAt(): ?\DateTimeImmutable
    {
        return $this->invadedAt;
    }

    public function isInvadedInWar(): bool
    {
        return $this->invadedInWar;
    }

    public function getIntegratedAt(): ?\DateTimeImmutable
    {
        return $this->integratedAt;
    }

    /** Invaded, but the integration period is not over yet. */
    public function isIntegrating(\DateTimeInterface $now): bool
    {
        return null !== $this->integratedAt && $this->integratedAt > $now;
    }

    /** The kind of world, given by the (topmost) slot the planet occupies. */
    /** A fusion always counts as a blue planet; a single planet has the type of its slot. */
    public function getType(): PlanetType
    {
        return $this->isFused() ? PlanetType::Blue : Universe::planetType($this->galaxy, $this->system, $this->position);
    }

    public function isHiddenInProfile(): bool
    {
        return $this->hiddenInProfile;
    }

    public function setHiddenInProfile(bool $hidden): void
    {
        $this->hiddenInProfile = $hidden;
    }

    public function getFusionSize(): int
    {
        return $this->fusionSize;
    }

    public function isFused(): bool
    {
        return $this->fusionSize > 1;
    }

    /** Bottom-most position this planet occupies. */
    public function getLastPosition(): int
    {
        return $this->position + $this->fusionSize - 1;
    }

    public function covers(int $position): bool
    {
        return $position >= $this->position && $position <= $this->getLastPosition();
    }

    /** @return list<array{position: int, name: string}> */
    public function getFusedPlanets(): array
    {
        return $this->fusedPlanets;
    }

    /** Takes over the slots of a planet directly below (which may itself be fused). */
    public function absorb(self $planet): void
    {
        if ($planet->getPosition() !== $this->getLastPosition() + 1) {
            throw new \LogicException('Only the planet directly below can be absorbed.');
        }

        $this->fusedPlanets = [
            ...$this->fusedPlanets,
            ['position' => $planet->getPosition(), 'name' => $planet->getName()],
            ...$planet->getFusedPlanets(),
        ];
        $this->fusionSize += $planet->getFusionSize();
    }

    /**
     * Detaches the bottom-most welded slot (it becomes a planet of its own again).
     *
     * @return array{position: int, name: string}
     */
    public function detachBottom(): array
    {
        if ([] === $this->fusedPlanets) {
            throw new \LogicException('Only a fusion has a bottom planet to detach.');
        }
        $bottom = array_pop($this->fusedPlanets);
        --$this->fusionSize;

        return $bottom;
    }

    public function resetFusion(): void
    {
        $this->fusedPlanets = [];
        $this->fusionSize = 1;
    }

    public function getResources(): Resources
    {
        return new Resources($this->iron, $this->titanium, $this->food, $this->water, $this->hydrogen);
    }

    public function setResources(Resources $resources): static
    {
        $this->iron = $resources->iron;
        $this->titanium = $resources->titanium;
        $this->food = $resources->food;
        $this->water = $resources->water;
        $this->hydrogen = $resources->hydrogen;

        return $this;
    }

    public function getPopulation(): float
    {
        return $this->population;
    }

    public function setPopulation(float $population): static
    {
        $this->population = $population;

        return $this;
    }

    public function getResourcesUpdatedAt(): \DateTimeImmutable
    {
        return $this->resourcesUpdatedAt;
    }

    public function setResourcesUpdatedAt(\DateTimeImmutable $at): static
    {
        $this->resourcesUpdatedAt = $at;

        return $this;
    }

    public function getBuildingLevel(BuildingType $type): int
    {
        return $this->buildings[$type->value] ?? 0;
    }

    public function setBuildingLevel(BuildingType $type, int $level): static
    {
        $this->buildings[$type->value] = $level;

        return $this;
    }

    public function getShipCount(ShipType $type): int
    {
        return $this->ships[$type->value] ?? 0;
    }

    public function addShips(ShipType $type, int $count): static
    {
        $this->ships[$type->value] = $this->getShipCount($type) + $count;
        $this->ships = array_filter($this->ships);

        return $this;
    }

    /** @param array<string, int> $ships */
    public function replaceShips(array $ships): void
    {
        $this->ships = array_filter($ships);
    }

    /**
     * An invasion: the planet changes hands. Queues are lost, and the new owner
     * only fully owns it after the integration period.
     */
    public function conquer(User $newOwner, \DateTimeImmutable $now, bool $inWar = false): void
    {
        $this->hiddenInProfile = false;
        $this->owner->getPlanets()->removeElement($this);
        $this->owner = $newOwner;
        $newOwner->addPlanet($this);
        foreach ($this->buildingOrders->toArray() as $order) {
            $this->buildingOrders->removeElement($order);
        }
        foreach ($this->shipyardOrders->toArray() as $order) {
            $this->shipyardOrders->removeElement($order);
        }
        $this->markInvaded($now, $inWar);
    }

    /** @return array<string, int> */
    public function getShips(): array
    {
        return array_filter($this->ships);
    }

    /** @return Collection<int, ShipyardOrder> in processing order */
    public function getShipyardOrders(): Collection
    {
        return $this->shipyardOrders;
    }

    public function addShipyardOrder(ShipyardOrder $order): void
    {
        $this->shipyardOrders->add($order);
    }

    public function removeShipyardOrder(ShipyardOrder $order): void
    {
        $this->shipyardOrders->removeElement($order);
    }

    /** @return Collection<int, BuildingOrder> in queue order */
    public function getBuildingOrders(): Collection
    {
        return $this->buildingOrders;
    }

    public function addBuildingOrder(BuildingOrder $order): void
    {
        $this->buildingOrders->add($order);
    }

    public function removeBuildingOrder(BuildingOrder $order): void
    {
        $this->buildingOrders->removeElement($order);
    }

    public function isConstructing(): bool
    {
        return !$this->buildingOrders->isEmpty();
    }

    /** Level a building will have once all queued upgrades are done. */
    public function getPlannedBuildingLevel(BuildingType $type): int
    {
        return $this->getBuildingLevel($type) + $this->buildingOrders->filter(
            static fn (BuildingOrder $order): bool => $order->getBuildingType() === $type,
        )->count();
    }

    /** @return list<BuildingOrder> building queue, first entry is under construction */
    public function getBuildingQueue(): array
    {
        return self::sorted($this->buildingOrders->toArray());
    }

    /** @return list<ShipyardOrder> ship queue of the shipyard, first entry is being built */
    public function getShipyardQueue(): array
    {
        return $this->getShipQueue(QueueKind::Shipyard);
    }

    /** @return list<ShipyardOrder> defense queue (runs in parallel to the ships), first entry is being built */
    public function getDefenseQueue(): array
    {
        return $this->getShipQueue(QueueKind::Defense);
    }

    /** @return list<BuildingOrder>|list<ShipyardOrder> */
    public function getQueue(QueueKind $kind): array
    {
        return QueueKind::Buildings === $kind ? $this->getBuildingQueue() : $this->getShipQueue($kind);
    }

    /** @return list<ShipyardOrder> */
    private function getShipQueue(QueueKind $kind): array
    {
        return self::sorted(array_filter(
            $this->shipyardOrders->toArray(),
            static fn (ShipyardOrder $order): bool => QueueKind::forShip($order->getShipType()) === $kind,
        ));
    }

    /**
     * @template T of QueueItem
     *
     * @param array<T> $items
     *
     * @return list<T>
     */
    private static function sorted(array $items): array
    {
        usort($items, static fn (QueueItem $a, QueueItem $b): int => [$a->getPosition(), $a->getId() ?? \PHP_INT_MAX] <=> [$b->getPosition(), $b->getId() ?? \PHP_INT_MAX]);

        return $items;
    }
}
