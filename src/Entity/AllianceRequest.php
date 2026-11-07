<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Either an invitation (the alliance asks a player) or an application (a player asks the alliance).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_alliance_request', columns: ['alliance_id', 'user_id'])]
class AllianceRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Alliance $alliance;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** true: invited by the alliance, false: the player applied. */
    #[ORM\Column]
    private bool $invitation;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Alliance $alliance, User $user, bool $invitation, \DateTimeImmutable $createdAt)
    {
        $this->alliance = $alliance;
        $this->user = $user;
        $this->invitation = $invitation;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAlliance(): Alliance
    {
        return $this->alliance;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isInvitation(): bool
    {
        return $this->invitation;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
