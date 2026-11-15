<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A note on the alliance black board (not visible to prospects).
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_alliance_post', columns: ['alliance_id', 'created_at'])]
class AlliancePost
{
    public const int MAX_LENGTH = 5000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Alliance $alliance;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** When the author last changed the text (null: never). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    public function __construct(Alliance $alliance, User $author, string $body, \DateTimeImmutable $createdAt)
    {
        $this->alliance = $alliance;
        $this->author = $author;
        $this->body = $body;
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

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEditedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    /** The author changes his text (checked by the service). */
    public function edit(string $body, \DateTimeImmutable $at): void
    {
        $this->body = mb_substr($body, 0, self::MAX_LENGTH);
        $this->editedAt = $at;
    }

    public function isWrittenBy(User $user): bool
    {
        return null !== $this->author && $this->author->isSameAs($user);
    }
}
