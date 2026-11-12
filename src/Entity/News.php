<?php

namespace App\Entity;

use App\Repository\NewsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A news item written by an admin. Players see it until they mark it read; the welcome
 * message is shown to every new player, normal news only to players who joined before it.
 */
#[ORM\Entity(repositoryClass: NewsRepository::class)]
#[ORM\Index(name: 'idx_news_created', columns: ['created_at'])]
class News
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Gib einen Titel ein.')]
    #[Assert\Length(max: 120)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Schreib etwas in die Neuigkeit.')]
    #[Assert\Length(max: 20000)]
    private string $body = '';

    /** Shown to every new player as his welcome message. */
    #[ORM\Column]
    private bool $welcome = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(\DateTimeImmutable $createdAt)
    {
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(?string $title): void { $this->title = trim((string) $title); }
    public function getBody(): string { return $this->body; }
    public function setBody(?string $body): void { $this->body = trim((string) $body); }
    public function isWelcome(): bool { return $this->welcome; }
    public function setWelcome(bool $welcome): void { $this->welcome = $welcome; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function touch(\DateTimeImmutable $at): void { $this->updatedAt = $at; }
}
