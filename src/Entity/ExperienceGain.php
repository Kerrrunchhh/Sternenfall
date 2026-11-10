<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Combat experience a player gained in one battle (for "most experience in the last 24 hours"). */
#[ORM\Entity]
#[ORM\Index(name: 'idx_experience_gain_at', columns: ['gained_at'])]
class ExperienceGain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private float $amount;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $gainedAt;

    public function __construct(User $user, float $amount, \DateTimeImmutable $gainedAt)
    {
        $this->user = $user;
        $this->amount = $amount;
        $this->gainedAt = $gainedAt;
    }
}
