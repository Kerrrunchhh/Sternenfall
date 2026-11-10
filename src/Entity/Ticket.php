<?php

namespace App\Entity;

use App\Game\TicketKind;
use App\Game\TicketStatus;
use App\Repository\TicketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A report about a player, a suggestion or a bug report – handled by the admins. */
#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Index(name: 'idx_ticket_status', columns: ['status', 'updated_at'])]
class Ticket
{
    public const int MAX_SUBJECT = 150;
    public const int MAX_BODY = 5000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: TicketKind::class)]
    private TicketKind $kind;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    /** The reported player (reports only). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reported;

    #[ORM\Column(length: self::MAX_SUBJECT)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(enumType: TicketStatus::class)]
    private TicketStatus $status = TicketStatus::Open;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, TicketComment> */
    #[ORM\OneToMany(targetEntity: TicketComment::class, mappedBy: 'ticket', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $comments;

    public function __construct(TicketKind $kind, User $author, ?User $reported, string $subject, string $body, \DateTimeImmutable $now)
    {
        $this->kind = $kind;
        $this->author = $author;
        $this->reported = $reported;
        $this->subject = mb_substr($subject, 0, self::MAX_SUBJECT);
        $this->body = mb_substr($body, 0, self::MAX_BODY);
        $this->createdAt = $this->updatedAt = $now;
        $this->comments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): TicketKind
    {
        return $this->kind;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getReported(): ?User
    {
        return $this->reported;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatus(): TicketStatus
    {
        return $this->status;
    }

    public function setStatus(TicketStatus $status, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->updatedAt = $now;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, TicketComment> */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function comment(?User $author, string $body, bool $fromAdmin, \DateTimeImmutable $now): TicketComment
    {
        $comment = new TicketComment($this, $author, $body, $fromAdmin, $now);
        $this->comments->add($comment);
        $this->updatedAt = $now;

        return $comment;
    }

    public function isByAuthor(User $user): bool
    {
        return null !== $this->author && $this->author->isSameAs($user);
    }
}
