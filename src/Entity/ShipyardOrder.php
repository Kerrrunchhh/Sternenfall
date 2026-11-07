<?php

namespace App\Entity;

use App\Game\Resources;
use App\Game\ShipType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A batch of ships in a planet's shipyard queue. Orders are built one after
 * another, one ship at a time; resources and crew are paid up front.
 */
#[ORM\Entity]
class ShipyardOrder implements QueueItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'shipyardOrders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Planet $planet;

    #[ORM\Column(enumType: ShipType::class)]
    private ShipType $shipType;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column]
    private int $remaining;

    #[ORM\Column]
    private int $unitSeconds;

    /** @var array<string, float> resources paid per ship (for refunds) */
    #[ORM\Column(type: Types::JSON)]
    private array $unitCost;

    /** Credits paid per ship (for refunds). */
    #[ORM\Column(options: ['default' => 0])]
    private float $unitCredits;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** Work already done on the current ship before the order was moved back in the queue. */
    #[ORM\Column(options: ['default' => 0])]
    private int $progressSeconds = 0;

    /** When work on the next ship of this order began; null while waiting in the queue. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt;

    public function __construct(
        Planet $planet,
        ShipType $shipType,
        int $quantity,
        int $unitSeconds,
        ?\DateTimeImmutable $startedAt,
        Resources $unitCost = new Resources(),
        float $unitCredits = 0,
        int $position = 0,
    ) {
        $this->position = $position;
        $this->unitCost = $unitCost->toArray();
        $this->unitCredits = $unitCredits;
        $this->planet = $planet;
        $this->shipType = $shipType;
        $this->quantity = $quantity;
        $this->remaining = $quantity;
        $this->unitSeconds = $unitSeconds;
        $this->startedAt = $startedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlanet(): Planet
    {
        return $this->planet;
    }

    public function getShipType(): ShipType
    {
        return $this->shipType;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getRemaining(): int
    {
        return $this->remaining;
    }

    public function getUnitSeconds(): int
    {
        return $this->unitSeconds;
    }

    public function getUnitCost(): Resources
    {
        return Resources::fromArray($this->unitCost);
    }

    public function getUnitCredits(): float
    {
        return $this->unitCredits;
    }

    public function getProgressSeconds(): int
    {
        return $this->progressSeconds;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    /** Starts (or resumes) work on the next ship. */
    public function start(\DateTimeImmutable $at): void
    {
        $this->startedAt = $at->modify('-'.min($this->progressSeconds, $this->unitSeconds - 1).' seconds');
        $this->progressSeconds = 0;
    }

    public function stop(\DateTimeImmutable $now): void
    {
        if (null !== $this->startedAt) {
            $this->progressSeconds = max(0, min($this->unitSeconds - 1, $now->getTimestamp() - $this->startedAt->getTimestamp()));
            $this->startedAt = null;
        }
    }

    /** When the whole order will be finished, if it is running. */
    public function getFinishesAt(): ?\DateTimeImmutable
    {
        return $this->getNextShipAt()?->modify('+'.(($this->remaining - 1) * $this->unitSeconds).' seconds');
    }

    public function isStarted(): bool
    {
        return null !== $this->startedAt;
    }

    /** When the next ship of this order will be finished (null if not started). */
    public function getNextShipAt(): ?\DateTimeImmutable
    {
        return $this->startedAt?->modify("+{$this->unitSeconds} seconds");
    }

    /**
     * Completes as many ships as fit until $now.
     *
     * @return int number of ships completed
     */
    public function advance(\DateTimeImmutable $now): int
    {
        if (null === $this->startedAt || 0 === $this->remaining) {
            return 0;
        }

        $elapsed = $now->getTimestamp() - $this->startedAt->getTimestamp();
        $done = min($this->remaining, intdiv(max(0, $elapsed), $this->unitSeconds));
        if ($done > 0) {
            $this->remaining -= $done;
            $this->startedAt = $this->startedAt->modify('+'.($done * $this->unitSeconds).' seconds');
        }

        return $done;
    }

    public function isFinished(): bool
    {
        return 0 === $this->remaining;
    }
}
