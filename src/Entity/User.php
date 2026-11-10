<?php

namespace App\Entity;

use App\Game\AllianceRank;
use App\Game\LifeForm;
use App\Game\ResearchType;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['username'], message: 'Dieser Name ist bereits vergeben.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 30)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9_\-]+$/', message: 'Nur Buchstaben, Ziffern, _ und - sind erlaubt.')]
    private string $username = '';

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(enumType: LifeForm::class, options: ['default' => 'settler'])]
    private LifeForm $lifeForm = LifeForm::Settler;

    /** Start of the current life form; the next change is possible 30 days later. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lifeFormSince;

    /** Last request of the player (online status for alliance mates and quick contacts). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    /** Start of the current online session (how long the player has been online). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $onlineSince = null;

    /** Player-wide currency, earned by the population of all planets. */
    #[ORM\Column(options: ['default' => 0])]
    private float $credits = 0;

    public const int NOTES_LIMIT = 50000;

    /** Private notepad of the player. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notesUpdatedAt = null;

    /** Gained only in battles: destroyed enemy shield points, shared by build time of the own units. */
    #[ORM\Column(options: ['default' => 0])]
    private float $combatExperience = 0;

    #[ORM\ManyToOne(inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Alliance $alliance = null;

    #[ORM\Column(nullable: true, enumType: AllianceRank::class)]
    private ?AllianceRank $allianceRank = null;

    /** File name of the profile picture (see ImageStore), if any. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $avatar = null;

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $profileText = '';

    /** @var list<array{name: string, color: string, alliance: bool, rules: list<array<string, mixed>>}>|null saved fleet filters */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $fleetFilters = null;

    /** @var array<string, int> research type value => level */
    #[ORM\Column(type: Types::JSON)]
    private array $research = [];

    #[ORM\Column(nullable: true, enumType: ResearchType::class)]
    private ?ResearchType $researchType = null;

    #[ORM\Column(nullable: true)]
    private ?int $researchTargetLevel = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $researchFinishesAt = null;

    /**
     * Planet the running research was started on (the top planet of a fusion). It keeps
     * running while planets break off; it ends when this planet itself is invaded.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Planet $researchPlanet = null;

    /** @var Collection<int, Planet> */
    #[ORM\OneToMany(targetEntity: Planet::class, mappedBy: 'owner')]
    #[ORM\OrderBy(['galaxy' => 'ASC', 'system' => 'ASC', 'position' => 'ASC'])]
    private Collection $planets;

    public function __construct(string $username = '', ?\DateTimeImmutable $createdAt = null)
    {
        $this->username = $username;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->lifeFormSince = $this->createdAt;
        $this->planets = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Planet> */
    public function getPlanets(): Collection
    {
        return $this->planets;
    }

    public function addPlanet(Planet $planet): static
    {
        if (!$this->planets->contains($planet)) {
            $this->planets->add($planet);
        }

        return $this;
    }

    public function getLifeForm(): LifeForm
    {
        return $this->lifeForm;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getOnlineSince(): ?\DateTimeImmutable
    {
        return $this->onlineSince;
    }

    /** A page view; a new online session starts if the player was offline before. */
    public function markSeen(\DateTimeImmutable $now, bool $newSession = false): void
    {
        if ($newSession || null === $this->onlineSince) {
            $this->onlineSince = $now;
        }
        $this->lastSeenAt = $now;
    }

    public function getLifeFormSince(): \DateTimeImmutable
    {
        return $this->lifeFormSince;
    }

    public function getNextLifeFormChangeAt(): \DateTimeImmutable
    {
        return $this->lifeFormSince->modify(LifeForm::CHANGE_COOLDOWN);
    }

    public function changeLifeForm(LifeForm $lifeForm, \DateTimeImmutable $now): void
    {
        $this->lifeForm = $lifeForm;
        $this->lifeFormSince = $now;
    }

    public function getAlliance(): ?Alliance
    {
        return $this->alliance;
    }

    public function getAllianceRank(): ?AllianceRank
    {
        return $this->allianceRank;
    }

    public function joinAlliance(Alliance $alliance, AllianceRank $rank): void
    {
        $this->alliance = $alliance;
        $this->allianceRank = $rank;
        $alliance->getMembers()->add($this);
    }

    public function setAllianceRank(AllianceRank $rank): void
    {
        $this->allianceRank = $rank;
    }

    public function leaveAlliance(): void
    {
        $this->alliance?->getMembers()->removeElement($this);
        $this->alliance = null;
        $this->allianceRank = null;
    }

    public function isAllyOf(self $other): bool
    {
        $theirs = $other->getAlliance();

        return null !== $this->alliance && null !== $theirs
            && ($theirs === $this->alliance || (null !== $theirs->getId() && $theirs->getId() === $this->alliance->getId()));
    }

    public function isSameAs(self $other): bool
    {
        return $other === $this || (null !== $this->id && $this->id === $other->getId());
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): void
    {
        $this->avatar = $avatar;
    }

    /** @return list<array{name: string, color: string, alliance: bool, rules: list<array<string, mixed>>}> */
    public function getFleetFilters(): array
    {
        return $this->fleetFilters ?? [];
    }

    /** @param list<array{name: string, color: string, alliance: bool, rules: list<array<string, mixed>>}> $filters */
    public function setFleetFilters(array $filters): void
    {
        $this->fleetFilters = array_values($filters);
    }

    public function getProfileText(): string
    {
        return $this->profileText;
    }

    public function setProfileText(string $text): void
    {
        $this->profileText = $text;
    }

    public function getNotes(): string
    {
        return $this->notes ?? '';
    }

    public function getNotesUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->notesUpdatedAt;
    }

    public function writeNotes(string $notes, \DateTimeImmutable $now): void
    {
        $this->notes = '' === trim($notes) ? null : mb_substr($notes, 0, self::NOTES_LIMIT);
        $this->notesUpdatedAt = $now;
    }

    public function getCombatExperience(): float
    {
        return $this->combatExperience;
    }

    public function addCombatExperience(float $experience): void
    {
        $this->combatExperience += max(0.0, $experience);
    }

    public function getCredits(): float
    {
        return $this->credits;
    }

    public function addCredits(float $amount): static
    {
        $this->credits += $amount;

        return $this;
    }

    public function getResearchLevel(ResearchType $type): int
    {
        return $this->research[$type->value] ?? 0;
    }

    public function setResearchLevel(ResearchType $type, int $level): static
    {
        $this->research[$type->value] = $level;

        return $this;
    }

    public function getResearchType(): ?ResearchType
    {
        return $this->researchType;
    }

    public function getResearchTargetLevel(): ?int
    {
        return $this->researchTargetLevel;
    }

    public function getResearchFinishesAt(): ?\DateTimeImmutable
    {
        return $this->researchFinishesAt;
    }

    public function isResearching(): bool
    {
        return null !== $this->researchType;
    }

    public function startResearch(ResearchType $type, int $targetLevel, \DateTimeImmutable $finishesAt, ?Planet $planet = null): void
    {
        $this->researchType = $type;
        $this->researchTargetLevel = $targetLevel;
        $this->researchFinishesAt = $finishesAt;
        $this->researchPlanet = $planet;
    }

    public function getResearchPlanet(): ?Planet
    {
        return $this->researchPlanet;
    }

    public function clearResearch(): void
    {
        $this->researchType = null;
        $this->researchTargetLevel = null;
        $this->researchFinishesAt = null;
        $this->researchPlanet = null;
    }

    /**
     * Only what the security layer needs to reload the user; game state is
     * always read fresh from the database. The password hash itself never
     * goes into the session.
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'roles' => $this->roles,
            'password' => hash('crc32c', $this->password),
        ];
    }

    public function __unserialize(array $data): void
    {
        // Sessions written before the slim format stored all properties under their mangled private names.
        $get = static fn (string $key): mixed => $data[$key] ?? $data["\0".self::class."\0".$key] ?? null;

        $this->id = $get('id');
        $this->username = (string) $get('username');
        $this->roles = $get('roles') ?? [];
        $this->password = (string) $get('password');
        $this->planets = new ArrayCollection();
    }
}
