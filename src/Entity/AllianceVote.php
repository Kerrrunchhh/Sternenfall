<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A vote to depose the founder in favour of a successor. Each eligible member has at most one.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_alliance_vote', columns: ['alliance_id', 'voter_id'])]
class AllianceVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Alliance $alliance;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $voter;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $candidate;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Alliance $alliance, User $voter, User $candidate, \DateTimeImmutable $createdAt)
    {
        $this->alliance = $alliance;
        $this->voter = $voter;
        $this->candidate = $candidate;
        $this->createdAt = $createdAt;
    }

    public function getAlliance(): Alliance
    {
        return $this->alliance;
    }

    public function getVoter(): User
    {
        return $this->voter;
    }

    public function getCandidate(): User
    {
        return $this->candidate;
    }

    public function setCandidate(User $candidate, \DateTimeImmutable $at): void
    {
        $this->candidate = $candidate;
        $this->createdAt = $at;
    }
}
