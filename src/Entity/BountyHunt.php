<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** How many shield points of the bounty target's spaceships one hunter has destroyed so far. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_bounty_hunter', columns: ['bounty_id', 'hunter_id'])]
class BountyHunt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private float $shield = 0.0;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'hunts')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Bounty $bounty,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $hunter,
    ) {
    }

    public function getId(): ?int { return $this->id; }
    public function getBounty(): Bounty { return $this->bounty; }
    public function getHunter(): User { return $this->hunter; }
    public function getShield(): float { return $this->shield; }

    public function add(float $shield): void
    {
        $this->shield += $shield;
    }
}
