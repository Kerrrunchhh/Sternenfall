<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A player in a ticker: invited until he accepts, then a member. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_ticker_member', columns: ['ticker_id', 'user_id'])]
class TickerMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ticker $ticker;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $invitedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    /** Appointed by the owner: invites, removes and mutes members and deletes posts. */
    #[ORM\Column(options: ['default' => false])]
    private bool $admin = false;

    /** Muted by the owner or an admin: reads, but can't post until then. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $mutedUntil = null;

    public function __construct(Ticker $ticker, User $user, \DateTimeImmutable $invitedAt)
    {
        $this->ticker = $ticker;
        $this->user = $user;
        $this->invitedAt = $invitedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicker(): Ticker
    {
        return $this->ticker;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getInvitedAt(): \DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function isAccepted(): bool
    {
        return null !== $this->acceptedAt;
    }

    public function accept(\DateTimeImmutable $now): void
    {
        $this->acceptedAt = $now;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function setAdmin(bool $admin): void
    {
        $this->admin = $admin;
    }

    public function getMutedUntil(): ?\DateTimeImmutable
    {
        return $this->mutedUntil;
    }

    public function isMuted(\DateTimeImmutable $now): bool
    {
        return null !== $this->mutedUntil && $this->mutedUntil > $now;
    }

    public function muteUntil(?\DateTimeImmutable $until): void
    {
        $this->mutedUntil = $until;
    }
}
