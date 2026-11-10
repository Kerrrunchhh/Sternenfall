<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A question, answer or note in a ticket; $fromAdmin marks the admins' side. */
#[ORM\Entity]
class TicketComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ticket $ticket;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column]
    private bool $fromAdmin;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Ticket $ticket, ?User $author, string $body, bool $fromAdmin, \DateTimeImmutable $createdAt)
    {
        $this->ticket = $ticket;
        $this->author = $author;
        $this->body = mb_substr($body, 0, Ticket::MAX_BODY);
        $this->fromAdmin = $fromAdmin;
        $this->createdAt = $createdAt;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isFromAdmin(): bool
    {
        return $this->fromAdmin;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
