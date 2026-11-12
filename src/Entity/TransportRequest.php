<?php

namespace App\Entity;

use App\Game\Resources;
use App\Repository\TransportRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A beginner asks for resources to one of his planets (at most one small freighter load)
 * and offers credits, held in escrow; a trader delivers and gets paid on arrival.
 */
#[ORM\Entity(repositoryClass: TransportRequestRepository::class)]
#[ORM\Index(name: 'idx_transport_request_status', columns: ['status'])]
class TransportRequest
{
    public const string OPEN = 'open';
    public const string ASSIGNED = 'assigned';
    public const string DONE = 'done';
    public const string CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $requester;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $galaxy;

    #[ORM\Column(name: 'solar_system', type: Types::SMALLINT)]
    private int $system;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(length: 30)]
    private string $planetName;

    /** @var array<string, float> */
    #[ORM\Column(type: Types::JSON)]
    private array $goods;

    #[ORM\Column]
    private float $offer;

    #[ORM\Column(length: 10)]
    private string $status = self::OPEN;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $trader = null;

    /** The fleet on its way with the goods. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Fleet $fleet = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $requester, Planet $planet, Resources $goods, float $offer, \DateTimeImmutable $now)
    {
        $this->requester = $requester;
        [$this->galaxy, $this->system, $this->position] = [$planet->getGalaxy(), $planet->getSystem(), $planet->getPosition()];
        $this->planetName = mb_substr($planet->getName(), 0, 30);
        $this->goods = $goods->toArray();
        $this->offer = $offer;
        $this->createdAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getRequester(): User { return $this->requester; }
    public function getGalaxy(): int { return $this->galaxy; }
    public function getSystem(): int { return $this->system; }
    public function getPosition(): int { return $this->position; }
    public function getCoordinates(): string { return \sprintf('%d:%d:%d', $this->galaxy, $this->system, $this->position); }
    public function getPlanetName(): string { return $this->planetName; }
    public function getGoods(): Resources { return Resources::fromArray($this->goods); }
    public function getOffer(): float { return $this->offer; }
    public function getStatus(): string { return $this->status; }
    public function getTrader(): ?User { return $this->trader; }
    public function getFleet(): ?Fleet { return $this->fleet; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** When the trade guild's delivery arrives (it has no real fleet); null for player deliveries. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $guildDeliveryAt = null;

    public function getGuildDeliveryAt(): ?\DateTimeImmutable { return $this->guildDeliveryAt; }

    /** The trade guild takes the request; the goods arrive at $at. */
    public function assignToGuild(User $guild, \DateTimeImmutable $at): void
    {
        $this->trader = $guild;
        $this->fleet = null;
        $this->guildDeliveryAt = $at;
        $this->status = self::ASSIGNED;
    }

    public function assign(User $trader, Fleet $fleet): void
    {
        $this->trader = $trader;
        $this->fleet = $fleet;
        $this->status = self::ASSIGNED;
    }

    public function reopen(): void
    {
        $this->trader = null;
        $this->fleet = null;
        $this->guildDeliveryAt = null;
        $this->status = self::OPEN;
    }

    public function close(string $status): void
    {
        $this->status = $status;
        $this->fleet = null;
    }
}
