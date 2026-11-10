<?php

namespace App\Entity;

use App\Repository\TickerPostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TickerPostRepository::class)]
#[ORM\Index(name: 'idx_ticker_post_ticker', columns: ['ticker_id', 'created_at'])]
class TickerPost
{
    public const int MAX_BODY = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ticker $ticker;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Ticker $ticker, User $author, string $body, \DateTimeImmutable $createdAt)
    {
        $this->ticker = $ticker;
        $this->author = $author;
        $this->body = mb_substr($body, 0, self::MAX_BODY);
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicker(): Ticker
    {
        return $this->ticker;
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
}
