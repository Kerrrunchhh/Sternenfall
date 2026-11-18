<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a player's credit statement (App\Game\CreditLedger): credits paid or received,
 * with the other party, the purpose and – for trades – the trade post and the goods.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_credit_booking_user', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_credit_booking_post', columns: ['trade_post_id', 'created_at'])]
#[ORM\Index(name: 'idx_credit_booking_created', columns: ['created_at'])]
class CreditBooking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Positive: received, negative: paid. */
    #[ORM\Column]
    private float $amount;

    #[ORM\Column(length: 20)]
    private string $kind;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $counterparty;

    /** Name of the other party when there is no player (the trade guild, the alliance treasury). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $counterpartyName;

    #[ORM\Column(type: Types::TEXT)]
    private string $purpose;

    /** Trades: the trade post (its owner's sales report). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TradePost $tradePost;

    /** @var array<string, float> trades: goods bought or sold */
    #[ORM\Column(type: Types::JSON)]
    private array $goods;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, float> $goods */
    public function __construct(User $user, float $amount, string $kind, ?User $counterparty, ?string $counterpartyName, string $purpose, ?TradePost $tradePost, array $goods, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->amount = $amount;
        $this->kind = $kind;
        $this->counterparty = $counterparty;
        $this->counterpartyName = $counterpartyName;
        $this->purpose = $purpose;
        $this->tradePost = $tradePost;
        $this->goods = $goods;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }

    public function getAmount(): float { return $this->amount; }

    public function getKind(): string { return $this->kind; }

    public function getCounterparty(): ?User { return $this->counterparty; }

    public function getCounterpartyName(): ?string { return $this->counterpartyName; }

    public function getPurpose(): string { return $this->purpose; }

    public function getTradePost(): ?TradePost { return $this->tradePost; }

    /** @return array<string, float> */
    public function getGoods(): array { return $this->goods; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
