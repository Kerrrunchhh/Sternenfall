<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A player marked a news item as read. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_news_read', columns: ['news_id', 'user_id'])]
class NewsRead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private News $news,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $readAt,
    ) {
    }

    public function getId(): ?int { return $this->id; }
    public function getNews(): News { return $this->news; }
    public function getUser(): User { return $this->user; }
    public function getReadAt(): \DateTimeImmutable { return $this->readAt; }
}
