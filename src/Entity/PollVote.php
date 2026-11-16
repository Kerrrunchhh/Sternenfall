<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One player's answer to a poll: the ticked options and, if he wants, why. One per player and poll. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_poll_vote', columns: ['poll_id', 'user_id'])]
class PollVote
{
    public const int MAX_REASON = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Poll $poll;

    /** Kept when the account is deleted, so the result stays (shown as a deleted player to admins). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $user;

    /** @var list<int> indexes into Poll::getOptions() */
    #[ORM\Column(type: Types::JSON)]
    private array $choices;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param list<int> $choices */
    public function __construct(Poll $poll, User $user, array $choices, ?string $reason, \DateTimeImmutable $createdAt)
    {
        $this->poll = $poll;
        $this->user = $user;
        $this->choices = array_values($choices);
        $this->reason = null === $reason || '' === trim($reason) ? null : mb_substr(trim($reason), 0, self::MAX_REASON);
        $this->createdAt = $createdAt;
    }

    public function getPoll(): Poll
    {
        return $this->poll;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    /** @return list<int> */
    public function getChoices(): array
    {
        return $this->choices;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
