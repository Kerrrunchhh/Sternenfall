<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the player activity log (App\Game\ActionLog): what a player did that matters for
 * fair play – fleet orders, deliveries, trades, payments, battles – and whom it concerned.
 * Only admins read it; entries are deleted after ActionLog::KEEP_DAYS.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_action_log_actor', columns: ['actor_id', 'created_at'])]
#[ORM\Index(name: 'idx_action_log_other', columns: ['other_id', 'created_at'])]
#[ORM\Index(name: 'idx_action_log_created', columns: ['created_at'])]
class ActionLogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $actor;

    /** The player the action concerned (receiver, trade partner, attacked player …), if any. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $other;

    #[ORM\Column(length: 20)]
    private string $kind;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $actor, string $kind, string $text, ?User $other, \DateTimeImmutable $createdAt)
    {
        $this->actor = $actor;
        $this->kind = $kind;
        $this->text = $text;
        $this->other = $other;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): User
    {
        return $this->actor;
    }

    public function getOther(): ?User
    {
        return $this->other;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
