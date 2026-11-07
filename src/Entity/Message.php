<?php

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A system message in a player's inbox (fleet reports, spy reports, ...).
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Index(name: 'idx_message_inbox', columns: ['recipient_id', 'is_read'])]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\Column(length: 150)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private bool $isRead = false;

    public function __construct(User $recipient, string $subject, string $body, \DateTimeImmutable $createdAt)
    {
        $this->recipient = $recipient;
        $this->subject = mb_substr($subject, 0, 150);
        $this->body = $body;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function markRead(): void
    {
        $this->isRead = true;
    }
}
