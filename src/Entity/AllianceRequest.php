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

    public const int MAX_MESSAGE = 1000;

    /** true: invited by the alliance, false: the player applied. */
    #[ORM\Column]
    private bool $invitation;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** The applicant's letter (applications only; required since applications need one). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /** When the alliance turned the application down; the applicant still sees it until he dismisses it. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $declinedAt = null;

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

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getDeclinedAt(): ?\DateTimeImmutable
    {
        return $this->declinedAt;
    }

    /** Still waiting for an answer. */
    public function isOpen(): bool
    {
        return null === $this->declinedAt;
    }

    /** A (new) application with its letter; a declined one starts over. */
    public function applyWith(string $message, \DateTimeImmutable $at): void
    {
        $this->message = mb_substr($message, 0, self::MAX_MESSAGE);
        $this->createdAt = $at;
        $this->declinedAt = null;
    }

    public function decline(\DateTimeImmutable $at): void
    {
        $this->declinedAt = $at;
    }
}
