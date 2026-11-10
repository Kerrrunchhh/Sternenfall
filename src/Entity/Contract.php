<?php

namespace App\Entity;

use App\Repository\ContractRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A contract with a cash reward held in escrow: protect one of the client's planets, or
 * invade a planet (of a fusion: blow it up first, then take its top planet).
 */
#[ORM\Entity(repositoryClass: ContractRepository::class)]
#[ORM\Index(name: 'idx_contract_status', columns: ['status', 'ends_at'])]
class Contract
{
    public const string PROTECTION = 'protection';
    public const string INVASION = 'invasion';
    public const string OPEN = 'open';
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string FAILED = 'failed';
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

    #[ORM\Column(length: 12)]
    private string $kind;

    /** The planet's slot (its top position for a fusion). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $galaxy;

    #[ORM\Column(name: 'solar_system', type: Types::SMALLINT)]
    private int $system;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(length: 30)]
    private string $planetName;

    #[ORM\Column]
    private float $reward;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $days;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    #[ORM\Column(length: 12)]
    private string $status = self::OPEN;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $contractor = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    /** @var Collection<int, ContractApplication> */
    #[ORM\OneToMany(targetEntity: ContractApplication::class, mappedBy: 'contract', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $applications;

    public function __construct(User $client, string $kind, Planet $planet, float $reward, int $days, string $note, \DateTimeImmutable $now)
    {
        $this->client = $client;
        $this->kind = $kind;
        [$this->galaxy, $this->system, $this->position] = [$planet->getGalaxy(), $planet->getSystem(), $planet->getPosition()];
        $this->planetName = mb_substr($planet->getName(), 0, 30);
        $this->reward = $reward;
        $this->days = $days;
        $this->note = '' === trim($note) ? null : mb_substr(trim($note), 0, 1000);
        $this->createdAt = $now;
        $this->applications = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): User { return $this->client; }
    public function getKind(): string { return $this->kind; }
    public function isProtection(): bool { return self::PROTECTION === $this->kind; }
    public function getGalaxy(): int { return $this->galaxy; }
    public function getSystem(): int { return $this->system; }
    public function getPosition(): int { return $this->position; }
    public function getCoordinates(): string { return \sprintf('%d:%d:%d', $this->galaxy, $this->system, $this->position); }
    public function getPlanetName(): string { return $this->planetName; }
    public function getReward(): float { return $this->reward; }
    public function getDays(): int { return $this->days; }
    public function getNote(): ?string { return $this->note; }
    public function getStatus(): string { return $this->status; }
    public function getContractor(): ?User { return $this->contractor; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }

    /** @return Collection<int, ContractApplication> */
    public function getApplications(): Collection { return $this->applications; }

    public function kindLabel(): string
    {
        return $this->isProtection() ? 'Planetenschutz' : 'Invasion';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::OPEN => 'Offen für Bewerbungen',
            self::RUNNING => 'Läuft',
            self::DONE => 'Erfüllt',
            self::FAILED => 'Nicht erfüllt',
            default => 'Zurückgezogen',
        };
    }

    public function applicationOf(User $user): ?ContractApplication
    {
        return $this->applications->findFirst(static fn (int $k, ContractApplication $a): bool => $a->getApplicant()->isSameAs($user));
    }

    public function apply(User $applicant, string $message, \DateTimeImmutable $now): void
    {
        $this->applications->add(new ContractApplication($this, $applicant, $message, $now));
    }

    public function assign(User $contractor, \DateTimeImmutable $now): void
    {
        $this->contractor = $contractor;
        $this->status = self::RUNNING;
        $this->endsAt = $now->modify('+'.$this->days.' days');
    }

    public function close(string $status): void
    {
        $this->status = $status;
    }

    /** Covers this slot of a planet (fusions cover several). */
    public function isOn(Planet $planet): bool
    {
        return $planet->getGalaxy() === $this->galaxy && $planet->getSystem() === $this->system && $planet->covers($this->position);
    }
}
