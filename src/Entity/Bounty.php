<?php

namespace App\Entity;

use App\Repository\BountyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A bounty on a player: whoever first destroys the required shield points of his spaceships
 * (summed over any number of battles) gets the reward, which is held in escrow until then.
 */
#[ORM\Entity(repositoryClass: BountyRepository::class)]
#[ORM\Index(name: 'idx_bounty_status', columns: ['status', 'ends_at'])]
class Bounty
{
    public const string OPEN = 'open';
    public const string DONE = 'done';
    public const string EXPIRED = 'expired';
    public const string CANCELLED = 'cancelled';
    public const int MIN_DAYS = 3;
    public const int MAX_DAYS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $client;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $target;

    #[ORM\Column]
    private float $reward;

    /** Shield points of the target's spaceships a hunter has to destroy. */
    #[ORM\Column]
    private float $requiredShield;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    #[ORM\Column(length: 12)]
    private string $status = self::OPEN;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $winner = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $endsAt;

    /** @var Collection<int, BountyHunt> */
    #[ORM\OneToMany(targetEntity: BountyHunt::class, mappedBy: 'bounty', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['shield' => 'DESC'])]
    private Collection $hunts;

    public function __construct(User $client, User $target, float $reward, float $requiredShield, int $days, string $note, \DateTimeImmutable $now)
    {
        $this->client = $client;
        $this->target = $target;
        $this->reward = $reward;
        $this->requiredShield = $requiredShield;
        $this->note = '' === trim($note) ? null : mb_substr(trim($note), 0, 1000);
        $this->createdAt = $now;
        $this->endsAt = $now->modify('+'.$days.' days');
        $this->hunts = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): User { return $this->client; }
    public function getTarget(): User { return $this->target; }
    public function getReward(): float { return $this->reward; }
    public function getRequiredShield(): float { return $this->requiredShield; }
    public function getNote(): ?string { return $this->note; }
    public function getStatus(): string { return $this->status; }
    public function isOpen(): bool { return self::OPEN === $this->status; }
    public function getWinner(): ?User { return $this->winner; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getEndsAt(): \DateTimeImmutable { return $this->endsAt; }

    /** @return Collection<int, BountyHunt> */
    public function getHunts(): Collection { return $this->hunts; }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::OPEN => 'Offen',
            self::DONE => 'Erfüllt',
            self::EXPIRED => 'Abgelaufen',
            default => 'Zurückgezogen',
        };
    }

    public function huntOf(User $hunter): ?BountyHunt
    {
        return $this->hunts->findFirst(static fn (int $k, BountyHunt $h): bool => $h->getHunter()->isSameAs($hunter));
    }

    /** Adds destroyed shield points for a hunter; returns his hunt. */
    public function record(User $hunter, float $shield): BountyHunt
    {
        $hunt = $this->huntOf($hunter);
        if (null === $hunt) {
            $hunt = new BountyHunt($this, $hunter);
            $this->hunts->add($hunt);
        }
        $hunt->add($shield);

        return $hunt;
    }

    /** The best hunter so far. */
    public function leader(): ?BountyHunt
    {
        $best = null;
        foreach ($this->hunts as $hunt) {
            if (null === $best || $hunt->getShield() > $best->getShield()) {
                $best = $hunt;
            }
        }

        return $best;
    }

    public function award(User $winner): void
    {
        $this->winner = $winner;
        $this->status = self::DONE;
    }

    public function close(string $status): void
    {
        $this->status = $status;
    }
}
