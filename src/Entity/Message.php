<?php

namespace App\Entity;

use App\Game\MessageFolder;
use App\Repository\MessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A system message in a player's inbox (fleet reports, spy reports, ...).
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Index(name: 'idx_message_inbox', columns: ['recipient_id', 'is_read'])]
#[ORM\Index(name: 'idx_message_folder', columns: ['recipient_id', 'folder'])]
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

    /** Optional button below the message: where it leads (a path in the game) and its text. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $actionUrl = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $actionLabel = null;

    /** The folder it is listed in (MessageFolder). */
    #[ORM\Column(length: 10, options: ['default' => 'general'])]
    private string $folder;

    public function __construct(User $recipient, string $subject, string $body, \DateTimeImmutable $createdAt, MessageFolder $folder = MessageFolder::General)
    {
        $this->recipient = $recipient;
        $this->subject = mb_substr($subject, 0, 150);
        $this->body = $body;
        $this->createdAt = $createdAt;
        $this->folder = $folder->value;
    }

    public function getFolder(): MessageFolder
    {
        return MessageFolder::tryFrom($this->folder) ?? MessageFolder::General;
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

    public function getActionUrl(): ?string
    {
        return $this->actionUrl;
    }

    public function getActionLabel(): ?string
    {
        return $this->actionLabel;
    }

    /** A button below the message, e.g. "Antworten →" to a support dialog. Only paths inside the game. */
    public function setAction(string $url, string $label): void
    {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            throw new \InvalidArgumentException('Only paths inside the game.');
        }
        $this->actionUrl = mb_substr($url, 0, 255);
        $this->actionLabel = mb_substr($label, 0, 80);
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
