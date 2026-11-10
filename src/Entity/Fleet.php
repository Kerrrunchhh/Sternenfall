<?php

namespace App\Entity;

use App\Game\FleetMission;
use App\Game\Resources;
use App\Game\ShipType;
use App\Repository\FleetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A group of ships outside a planet's hangar.
 *
 * - Named fleets persist: they wait ("idle") at their position, which can be any
 *   coordinate, fly missions from there and come back to it.
 * - Unnamed fleets are quick dispatches from a hangar; they dissolve back into
 *   the hangar of the planet they return to.
 *
 * While moving, the position is the point of departure the fleet returns to.
 */
#[ORM\Entity(repositoryClass: FleetRepository::class)]
#[ORM\Index(name: 'idx_fleet_arrive', columns: ['returning', 'arrive_at'])]
#[ORM\Index(name: 'idx_fleet_return', columns: ['returning', 'return_at'])]
class Fleet
{
    public const int MAX_NAME_LENGTH = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: self::MAX_NAME_LENGTH, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $galaxy;

    #[ORM\Column(name: 'solar_system', type: Types::SMALLINT)]
    private int $system;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    /** @var array<string, int> ship type value => count */
    #[ORM\Column(type: Types::JSON)]
    private array $ships;

    /** @var array<string, float> resource type value => amount */
    #[ORM\Column(type: Types::JSON)]
    private array $cargo;

    // --- movement (all null while idle) ---

    #[ORM\Column(nullable: true, enumType: FleetMission::class)]
    private ?FleetMission $mission = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $targetGalaxy = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $targetSystem = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $targetPosition = null;

    /** Own planet the fleet was sent to a trade post from; "trade and fly back" returns there. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $homeGalaxy = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $homeSystem = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $homePosition = null;

    /** @var array<string, float>|null for trade missions: goods => amount to buy */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $tradeOrder = null;

    /** For gathering flights: the fleet the ships join on arrival. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Fleet $targetFleet = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $departAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $arriveAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $returnAt = null;

    #[ORM\Column]
    private bool $returning = false;

    /** Since when the fleet waits where it is (trade posts and foreign planets send it home after 24 h). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $parkedAt = null;

    /** Could not pay its way home from a foreign planet: since then it loses 10 % of its ships a day. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $strandedSince = null;

    /** @var array<string, int>|null ships when it got stranded (the losses are counted from them) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $strandedShips = null;

    // --- release to the alliance ---

    /** Visible to and controllable by all alliance members whose rank has fleet rights. */
    #[ORM\Column(options: ['default' => false])]
    private bool $releasedToAlliance = false;

    /** @var Collection<int, User> individual alliance members who may see and control this fleet */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'fleet_release')]
    private Collection $releasedTo;

    /**
     * @param array<string, int> $ships
     */
    public function __construct(User $owner, ?string $name, int $galaxy, int $system, int $position, array $ships, Resources $cargo = new Resources())
    {
        $this->owner = $owner;
        $this->name = $name;
        $this->galaxy = $galaxy;
        $this->system = $system;
        $this->position = $position;
        $this->ships = array_filter($ships);
        $this->cargo = $cargo->toArray();
        $this->releasedTo = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    /** The owner's account is deleted: the fleet stays behind without an owner. */
    public function handOverTo(User $nobody): void
    {
        $this->owner = $nobody;
        $this->releasedToAlliance = false;
        $this->releasedTo->clear();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function isNamed(): bool
    {
        return null !== $this->name;
    }

    /** Name for display; unnamed quick dispatches are described by their mission. */
    public function getLabel(): string
    {
        return $this->name ?? ($this->mission?->label() ?? 'Flotte').' von ['.$this->getCoordinates().']';
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
        return \sprintf('%d:%d:%d', $this->galaxy, $this->system, $this->position);
    }

    public function isAt(int $galaxy, int $system, int $position): bool
    {
        return [$this->galaxy, $this->system, $this->position] === [$galaxy, $system, $position];
    }

    // --- ships & cargo ---

    /** @return array<string, int> */
    public function getShips(): array
    {
        return $this->ships;
    }

    /** @return list<array{type: ShipType, count: int}> in firing order */
    public function getShipList(): array
    {
        $list = [];
        foreach (ShipType::cases() as $type) {
            if ($this->getShipCount($type) > 0) {
                $list[] = ['type' => $type, 'count' => $this->getShipCount($type)];
            }
        }

        return $list;
    }

    public function getShipCount(ShipType $type): int
    {
        return $this->ships[$type->value] ?? 0;
    }

    public function addShips(ShipType $type, int $count): void
    {
        $this->ships[$type->value] = $this->getShipCount($type) + $count;
        $this->ships = array_filter($this->ships);
    }

    public function removeShips(ShipType $type, int $count): void
    {
        $this->addShips($type, -min($count, $this->getShipCount($type)));
    }

    /** @param array<string, int> $ships */
    public function replaceShips(array $ships): void
    {
        $this->ships = array_filter($ships);
    }

    /** An interceptor waits at the meeting point until just before its prey arrives. */
    public function delayArrivalUntil(\DateTimeImmutable $at): void
    {
        $delay = $at->getTimestamp() - $this->arriveAt->getTimestamp();
        if ($delay > 0) {
            $this->arriveAt = $this->arriveAt->modify("+{$delay} seconds");
            $this->returnAt = $this->returnAt->modify("+{$delay} seconds");
        }
    }

    public function hasShips(): bool
    {
        return [] !== $this->ships;
    }

    public function getCargo(): Resources
    {
        return Resources::fromArray($this->cargo);
    }

    public function setCargo(Resources $cargo): void
    {
        $this->cargo = $cargo->toArray();
    }

    /** Takes over ships and cargo of another fleet. */
    public function merge(self $other): void
    {
        foreach ($other->getShips() as $type => $count) {
            $this->addShips(ShipType::from($type), $count);
        }
        $this->setCargo($this->getCargo()->add($other->getCargo()));
    }

    // --- movement ---

    public function isIdle(): bool
    {
        return null === $this->mission;
    }

    public function isMoving(): bool
    {
        return null !== $this->mission;
    }

    public function depart(FleetMission $mission, int $galaxy, int $system, int $position, \DateTimeImmutable $now, int $flightSeconds, ?self $targetFleet = null): void
    {
        $this->parkedAt = null;
        $this->strandedSince = null;
        $this->strandedShips = null;
        $this->mission = $mission;
        $this->targetGalaxy = $galaxy;
        $this->targetSystem = $system;
        $this->targetPosition = $position;
        $this->targetFleet = $targetFleet;
        $this->departAt = $now;
        $this->arriveAt = $now->modify("+{$flightSeconds} seconds");
        $this->returnAt = $this->arriveAt->modify("+{$flightSeconds} seconds");
        $this->returning = false;
    }

    /** Ends the movement at the point of departure. */
    public function land(): void
    {
        $this->parkedAt = ($this->returning ? $this->returnAt : $this->arriveAt) ?? $this->parkedAt;
        $this->mission = null;
        $this->targetGalaxy = $this->targetSystem = $this->targetPosition = null;
        $this->targetFleet = null;
        $this->tradeOrder = null;
        $this->departAt = $this->arriveAt = $this->returnAt = null;
        $this->returning = false;
    }

    /** Ends the movement at the target, which becomes the new position. */
    /**
     * A slot was welded into the fusion at $toPosition: the fleet's place and target follow.
     *
     * @param list<int> $fromPositions
     */
    public function followFusion(int $galaxy, int $system, array $fromPositions, int $toPosition): void
    {
        if ($this->galaxy === $galaxy && $this->system === $system && \in_array($this->position, $fromPositions, true)) {
            $this->position = $toPosition;
        }
        if ($this->targetGalaxy === $galaxy && $this->targetSystem === $system && \in_array($this->targetPosition, $fromPositions, true)) {
            $this->targetPosition = $toPosition;
        }
        if ($this->homeGalaxy === $galaxy && $this->homeSystem === $system && \in_array($this->homePosition, $fromPositions, true)) {
            $this->homePosition = $toPosition;
        }
    }

    /** An empty fleet moves without flight time. */
    public function relocate(int $galaxy, int $system, int $position): void
    {
        if (!$this->isIdle() || $this->hasShips()) {
            throw new \LogicException('Only an empty waiting fleet can be relocated.');
        }
        [$this->galaxy, $this->system, $this->position] = [$galaxy, $system, $position];
    }

    public function rememberHome(int $galaxy, int $system, int $position): void
    {
        [$this->homeGalaxy, $this->homeSystem, $this->homePosition] = [$galaxy, $system, $position];
    }

    public function forgetHome(): void
    {
        $this->homeGalaxy = $this->homeSystem = $this->homePosition = null;
    }

    public function getParkedAt(): ?\DateTimeImmutable
    {
        return $this->parkedAt;
    }

    /** After the owner's vacation the stay limits count on from where they stopped. */
    public function shiftParking(int $seconds): void
    {
        $this->parkedAt = $this->parkedAt?->modify("+{$seconds} seconds");
        $this->strandedSince = $this->strandedSince?->modify("+{$seconds} seconds");
    }

    public function park(\DateTimeImmutable $since): void
    {
        $this->parkedAt = $since;
    }

    public function unstrand(): void
    {
        $this->strandedSince = null;
        $this->strandedShips = null;
    }

    public function getStrandedSince(): ?\DateTimeImmutable
    {
        return $this->strandedSince;
    }

    /**
     * Stranded on a foreign planet without fuel: 10 % of the ships (counted from those
     * it had then) are lost every full day; after 10 days nothing is left.
     *
     * @return int ships lost by this call
     */
    public function strand(\DateTimeImmutable $since, \DateTimeImmutable $now): int
    {
        if (null === $this->strandedSince) {
            $this->strandedSince = $since;
            $this->strandedShips = $this->ships;
        }
        $days = intdiv(max(0, $now->getTimestamp() - $this->strandedSince->getTimestamp()), 86400);
        $share = max(0.0, 1 - 0.1 * $days);
        $lost = 0;
        foreach ($this->strandedShips as $type => $count) {
            $left = (int) ceil($count * $share - 1e-9);
            $current = $this->ships[$type] ?? 0;
            if ($current > $left) {
                $lost += $current - $left;
                $this->ships[$type] = $left;
            }
        }
        $this->ships = array_filter($this->ships);

        return $lost;
    }

    /** @return array{int, int, int}|null */
    public function getHome(): ?array
    {
        return null === $this->homeGalaxy ? null : [$this->homeGalaxy, $this->homeSystem, $this->homePosition];
    }

    public function settleAtTarget(): void
    {
        [$this->galaxy, $this->system, $this->position] = [$this->targetGalaxy, $this->targetSystem, $this->targetPosition];
        $this->land();
    }

    public function getMission(): ?FleetMission
    {
        return $this->mission;
    }

    public function getTargetGalaxy(): ?int
    {
        return $this->targetGalaxy;
    }

    public function getTargetSystem(): ?int
    {
        return $this->targetSystem;
    }

    public function getTargetPosition(): ?int
    {
        return $this->targetPosition;
    }

    public function getTargetCoordinates(): ?string
    {
        return null === $this->targetGalaxy ? null : \sprintf('%d:%d:%d', $this->targetGalaxy, $this->targetSystem, $this->targetPosition);
    }

    /** @return array<string, float> */
    public function getTradeOrder(): array
    {
        return $this->tradeOrder ?? [];
    }

    /** @param array<string, float> $order */
    public function setTradeOrder(array $order): void
    {
        $this->tradeOrder = [] === $order ? null : $order;
    }

    public function getTargetFleet(): ?self
    {
        return $this->targetFleet;
    }

    /** Called when the targeted fleet no longer exists. */
    public function forgetTargetFleet(): void
    {
        $this->targetFleet = null;
    }

    public function getDepartAt(): ?\DateTimeImmutable
    {
        return $this->departAt;
    }

    public function getArriveAt(): ?\DateTimeImmutable
    {
        return $this->arriveAt;
    }

    public function getReturnAt(): ?\DateTimeImmutable
    {
        return $this->returnAt;
    }

    public function isReturning(): bool
    {
        return $this->returning;
    }

    /** Time of the next thing that happens to this fleet (null while idle). */
    public function getNextEventAt(): ?\DateTimeImmutable
    {
        return $this->returning ? $this->returnAt : $this->arriveAt;
    }

    /**
     * Joining an attack: the fleet arrives together with the leading fleet (it waits
     * for it), the way back takes as long as its own flight.
     */
    public function arriveTogetherWith(self $lead): void
    {
        $flight = $this->arriveAt->getTimestamp() - $this->departAt->getTimestamp();
        $this->arriveAt = $lead->getArriveAt();
        $this->returnAt = $this->arriveAt->modify("+{$flight} seconds");
        $this->targetFleet = $lead;
    }

    /** Turns around after reaching the target (return time as planned at departure). */
    public function startReturn(): void
    {
        $this->returning = true;
    }

    /** Turns around mid-flight: the way back takes as long as the way so far. */
    public function recall(\DateTimeImmutable $now): void
    {
        $elapsed = max(0, $now->getTimestamp() - $this->departAt->getTimestamp());
        $this->returning = true;
        $this->returnAt = $now->modify("+{$elapsed} seconds");
    }

    // --- release ---

    public function isReleasedToAlliance(): bool
    {
        return $this->releasedToAlliance;
    }

    public function setReleasedToAlliance(bool $released): void
    {
        $this->releasedToAlliance = $released;
    }

    /** @return Collection<int, User> */
    public function getReleasedTo(): Collection
    {
        return $this->releasedTo;
    }

    public function releaseTo(User $user): void
    {
        if (!$this->releasedTo->contains($user)) {
            $this->releasedTo->add($user);
        }
    }

    public function revokeFrom(User $user): void
    {
        foreach ($this->releasedTo as $released) {
            if ($released->isSameAs($user)) {
                $this->releasedTo->removeElement($released);
            }
        }
    }

    public function isReleasedTo(User $user): bool
    {
        return $this->releasedTo->exists(static fn (int $key, User $released): bool => $released->isSameAs($user));
    }

    /**
     * Who may see and steer (send, recall) this fleet: the owner, alliance members
     * whose rank has fleet rights if it is released to the alliance, and members it
     * was released to individually. Releases only count within the same alliance.
     */
    public function isControllableBy(User $user): bool
    {
        if ($this->owner->isSameAs($user)) {
            return true;
        }
        if (!$user->isAllyOf($this->owner)) {
            return false;
        }

        return ($this->releasedToAlliance && true === $user->getAllianceRank()?->hasFleetRights())
            || $this->isReleasedTo($user);
    }

    /** Takes back every release (e.g. when the owner leaves the alliance). */
    public function revokeAllReleases(): void
    {
        $this->releasedToAlliance = false;
        $this->releasedTo->clear();
    }
}
