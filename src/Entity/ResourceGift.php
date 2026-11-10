<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Resources a player sent to another player's planet without payment (a transport). */
#[ORM\Entity]
#[ORM\Index(name: 'idx_resource_gift_sender', columns: ['sender_id', 'created_at'])]
class ResourceGift
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $sender;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $receiver;

    #[ORM\Column]
    private float $amount;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $sender, User $receiver, float $amount, \DateTimeImmutable $createdAt)
    {
        $this->sender = $sender;
        $this->receiver = $receiver;
        $this->amount = $amount;
        $this->createdAt = $createdAt;
    }
}
