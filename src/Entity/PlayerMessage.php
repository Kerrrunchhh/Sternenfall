<?php

namespace App\Entity;

use App\Repository\PlayerMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A letter from one player to another ("Post"). Sender and recipient each keep their
 * own copy in view until they delete it; the row goes once both have deleted it.
 */
#[ORM\Entity(repositoryClass: PlayerMessageRepository::class)]
#[ORM\Index(name: 'idx_player_message_inbox', columns: ['recipient_id', 'deleted_by_recipient', 'is_read'])]
#[ORM\Index(name: 'idx_player_message_sent', columns: ['sender_id', 'deleted_by_sender'])]
class PlayerMessage
{
    public const int MAX_SUBJECT = 150;
    public const int MAX_BODY = 5000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $sender;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $recipient;

    #[ORM\Column(length: self::MAX_SUBJECT)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private bool $isRead = false;

    #[ORM\Column]
    private bool $deletedBySender = false;

    #[ORM\Column]
    private bool $deletedByRecipient = false;

    public function __construct(User $sender, User $recipient, string $subject, string $body, \DateTimeImmutable $createdAt)
    {
        $this->sender = $sender;
        $this->recipient = $recipient;
        $this->subject = mb_substr($subject, 0, self::MAX_SUBJECT);
        $this->body = mb_substr($body, 0, self::MAX_BODY);
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSender(): ?User
    {
        return $this->sender;
    }

    public function getRecipient(): ?User
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

    public function isSentBy(User $user): bool
    {
        return null !== $this->sender && $this->sender->isSameAs($user);
    }

    public function isReceivedBy(User $user): bool
    {
        return null !== $this->recipient && $this->recipient->isSameAs($user);
    }

    /** Whether the player still sees this letter (in the inbox or among the sent ones). */
    public function isVisibleTo(User $user): bool
    {
        return ($this->isReceivedBy($user) && !$this->deletedByRecipient)
            || ($this->isSentBy($user) && !$this->deletedBySender);
    }

    /** Hides the letter for this player; true once nobody sees it any more. */
    public function deleteFor(User $user): bool
    {
        if ($this->isReceivedBy($user)) {
            $this->deletedByRecipient = true;
        }
        if ($this->isSentBy($user)) {
            $this->deletedBySender = true;
        }

        return ($this->deletedByRecipient || null === $this->recipient) && ($this->deletedBySender || null === $this->sender);
    }
}
