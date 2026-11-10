<?php

namespace App\Entity;

use App\Game\BuildingType;
use App\Game\Resources;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One building upgrade in a planet's building queue. Paid when queued; the
 * first entry of the queue is under construction, the others wait.
 */
#[ORM\Entity]
class BuildingOrder implements QueueItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'buildingOrders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Planet $planet;

    #[ORM\Column(enumType: BuildingType::class)]
    private BuildingType $buildingType;

    #[ORM\Column]
    private int $targetLevel;

    /** @var array<string, float> resources paid (for refunds) */
    #[ORM\Column(type: Types::JSON)]
    private array $cost;

    #[ORM\Column]
    private int $position;

    /** Build time, fixed when construction starts (depends on the command center at that time). */
    #[ORM\Column(nullable: true)]
    private ?int $seconds = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    /** Work already done before the order was moved back in the queue. */
    #[ORM\Column(options: ['default' => 0])]
    private int $progressSeconds = 0;

    public function __construct(Planet $planet, BuildingType $buildingType, int $targetLevel, Resources $cost, int $position)
    {
        $this->planet = $planet;
        $this->buildingType = $buildingType;
        $this->targetLevel = $targetLevel;
        $this->cost = $cost->toArray();
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlanet(): Planet
    {
        return $this->planet;
    }

    public function getBuildingType(): BuildingType
    {
        return $this->buildingType;
    }

    public function getTargetLevel(): int
    {
        return $this->targetLevel;
    }

    public function getCost(): Resources
    {
        return Resources::fromArray($this->cost);
    }

    /**
     * Swaps level, cost and progress with another order of the same building, so
     * that upgrades of one building always run in ascending level order.
     */
    public function swapPayload(self $other): void
    {
        [$this->targetLevel, $other->targetLevel] = [$other->targetLevel, $this->targetLevel];
        [$this->cost, $other->cost] = [$other->cost, $this->cost];
        [$this->seconds, $other->seconds] = [$other->seconds, $this->seconds];
        [$this->startedAt, $other->startedAt] = [$other->startedAt, $this->startedAt];
        [$this->progressSeconds, $other->progressSeconds] = [$other->progressSeconds, $this->progressSeconds];
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getSeconds(): ?int
    {
        return $this->seconds;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function isStarted(): bool
    {
        return null !== $this->startedAt;
    }

    public function getProgressSeconds(): int
    {
        return $this->progressSeconds;
    }

    /** Vacation: the running construction continues as if no time had passed. */
    public function shiftClock(int $seconds): void
    {
        $this->startedAt = $this->startedAt?->modify("+{$seconds} seconds");
    }

    /** Starts (or resumes) construction; $seconds is only used the first time. */
    public function start(\DateTimeImmutable $at, int $seconds): void
    {
        $this->seconds ??= $seconds;
        $this->startedAt = $at->modify('-'.min($this->progressSeconds, $this->seconds - 1).' seconds');
        $this->progressSeconds = 0;
    }

    public function stop(\DateTimeImmutable $now): void
    {
        if (null !== $this->startedAt) {
            $this->progressSeconds = max(0, min($this->seconds - 1, $now->getTimestamp() - $this->startedAt->getTimestamp()));
            $this->startedAt = null;
        }
    }

    public function getFinishesAt(): ?\DateTimeImmutable
    {
        return $this->startedAt?->modify("+{$this->seconds} seconds");
    }
}
