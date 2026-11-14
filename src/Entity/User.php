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
#[UniqueEntity(fields: ['email'], message: 'Diese E-Mail-Adresse wird schon verwendet.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** Without logging in for this long a player counts as inactive (see isInactive()). */
    public const string INACTIVE_AFTER = '30 days';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 30)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9_\-]+$/', message: 'Nur Buchstaben, Ziffern, _ und - sind erlaubt.')]
    private string $username = '';

    /** Confirmed by a link before the player can log in (accounts from before have none). */
    #[ORM\Column(length: 180, unique: true, nullable: true)]
    #[Assert\Email(message: 'Bitte gib eine gültige E-Mail-Adresse ein.')]
    private ?string $email = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    /** When the player accepted the terms of use (AGB) at sign-up; null for players from before. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    /** @var list<string> e-mail notifications the player wants (see App\Game\GameMailer::TOPICS) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $mailTopics = null;

    /** At most this many attack warnings per minute (0 = no limit). */
    #[ORM\Column(options: ['default' => 3])]
    private int $attackMailsPerMinute = 3;

    /** @var list<int> timestamps of the attack warnings sent in the last minute */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $attackMailTimes = null;

    /** The finished research was already announced by e-mail. */
    #[ORM\Column(options: ['default' => false])]
    private bool $researchMailSent = false;

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

    /** The owner of ownerless planets (left behind by deleted accounts); nobody logs in as him. */
    #[ORM\Column(name: 'is_system', options: ['default' => false])]
    private bool $system = false;

    /** Account deletion requested: at this time the account is gone (logging in cancels it). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deleteAt = null;

    /** On deletion the buildings of the planets are destroyed (else the planets are just left). */
    #[ORM\Column(options: ['default' => false])]
    private bool $deleteDestroysBuildings = false;

    /** Set by an admin: no login, hidden from highscores and search, planets ownerless – can be reactivated. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    /** Vacation: everything rests from $vacationSince to $vacationUntil. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $vacationSince = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $vacationUntil = null;

    /** A penalty: the player can't end this vacation early. */
    #[ORM\Column(options: ['default' => false])]
    private bool $vacationForced = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vacationReason = null;

    /** Language of the interface, system messages and e-mails (see translation.yaml enabled_locales). */
    /** IANA time zone times are shown in (App\I18n\DisplayZone); the game itself computes in UTC. */
    #[ORM\Column(length: 64, options: ['default' => \App\I18n\DisplayZone::DEFAULT])]
    private string $timezone = \App\I18n\DisplayZone::DEFAULT;

    #[ORM\Column(length: 5, options: ['default' => 'de'])]
    private string $locale = 'de';

    /** Extended account (paid subscription) runs until then. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $extendedUntil = null;

    /** PayPal subscription that keeps the extended account running. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $subscriptionId = null;

    /** Last known state of that subscription (ACTIVE, CANCELLED, SUSPENDED, …). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $subscriptionStatus = null;

    /** Private notepad of the player. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notesUpdatedAt = null;

    /** Gained only in battles: destroyed enemy shield points, shared by build time of the own units. */
    #[ORM\Column(options: ['default' => 0])]
    private float $combatExperience = 0;

    /** Below 0 the player is criminal (see App\Game\HeroPoints). */
    #[ORM\Column(options: ['default' => 1000])]
    private float $heroPoints = \App\Game\HeroPoints::START;

    /** Last time crime points were added: from then on hero points recover (HeroPoints::RECOVERY_PER_DAY). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCrimeAt = null;

    #[ORM\ManyToOne(inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Alliance $alliance = null;

    #[ORM\Column(nullable: true, enumType: AllianceRank::class)]
    private ?AllianceRank $allianceRank = null;

    /** Ticker the player shows below the alliance's black board (e.g. to keep in touch with a former alliance). */
    #[ORM\ManyToOne(targetEntity: Ticker::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Ticker $boardTicker = null;

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

    /** Full duration of the running research (null for research started before this was kept). */
    #[ORM\Column(nullable: true)]
    private ?int $researchSeconds = null;

    /**
     * Slot ("g:s:p") of the planet the running research was started on. It keeps running
     * there even when that planet is welded into a bigger fusion or other planets break
     * off; it ends when the planet on this slot is invaded (or given up).
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $researchSlot = null;

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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $email = null === $email ? null : mb_strtolower(trim($email));
        if ($email !== $this->email) {
            $this->email = '' === $email ? null : $email;
            $this->emailVerifiedAt = null;
        }

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->email && null !== $this->emailVerifiedAt;
    }

    /** Players who gave an address must confirm it before they can log in. */
    public function needsEmailConfirmation(): bool
    {
        return null !== $this->email && null === $this->emailVerifiedAt;
    }

    public function verifyEmail(\DateTimeImmutable $at): void
    {
        $this->emailVerifiedAt = $at;
    }

    public function getTermsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function acceptTerms(\DateTimeImmutable $at): void
    {
        $this->termsAcceptedAt = $at;
    }

    /** @return list<string> until the player chooses, every notification is on */
    public function getMailTopics(): array
    {
        return $this->mailTopics ?? array_keys(\App\Game\GameMailer::TOPICS);
    }

    /** @param list<string> $topics */
    public function setMailTopics(array $topics): void
    {
        $this->mailTopics = array_values($topics);
    }

    public function wantsMail(string $topic): bool
    {
        return !$this->isDeactivated() && $this->isEmailVerified() && \in_array($topic, $this->getMailTopics(), true);
    }

    public function getAttackMailsPerMinute(): int
    {
        return $this->attackMailsPerMinute;
    }

    public function setAttackMailsPerMinute(int $limit): void
    {
        $this->attackMailsPerMinute = max(0, min(60, $limit));
    }

    /** Counts an attack warning; false if the player's limit for this minute is reached. */
    public function allowAttackMail(\DateTimeImmutable $now): bool
    {
        $since = $now->getTimestamp() - 60;
        $times = array_values(array_filter($this->attackMailTimes ?? [], static fn (int $t): bool => $t > $since));
        if ($this->attackMailsPerMinute > 0 && \count($times) >= $this->attackMailsPerMinute) {
            $this->attackMailTimes = $times;

            return false;
        }
        $times[] = $now->getTimestamp();
        $this->attackMailTimes = $times;

        return true;
    }

    public function isResearchMailSent(): bool
    {
        return $this->researchMailSent;
    }

    public function markResearchMailSent(bool $sent = true): void
    {
        $this->researchMailSent = $sent;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function markSystem(): void
    {
        $this->system = true;
    }

    public function getDeleteAt(): ?\DateTimeImmutable
    {
        return $this->deleteAt;
    }

    public function isDeleteDestroyingBuildings(): bool
    {
        return $this->deleteDestroysBuildings;
    }

    public function requestDeletion(\DateTimeImmutable $at, bool $destroyBuildings): void
    {
        $this->deleteAt = $at;
        $this->deleteDestroysBuildings = $destroyBuildings;
    }

    public function cancelDeletion(): void
    {
        $this->deleteAt = null;
        $this->deleteDestroysBuildings = false;
    }

    public function isDeactivated(): bool
    {
        return null !== $this->deactivatedAt;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function deactivate(\DateTimeImmutable $at): void
    {
        $this->deactivatedAt = $at;
    }

    public function reactivate(): void
    {
        $this->deactivatedAt = null;
    }

    public function getVacationSince(): ?\DateTimeImmutable
    {
        return $this->vacationSince;
    }

    public function getVacationUntil(): ?\DateTimeImmutable
    {
        return $this->vacationUntil;
    }

    public function isVacationForced(): bool
    {
        return $this->vacationForced;
    }

    public function getVacationReason(): ?string
    {
        return $this->vacationReason;
    }

    /** On vacation at this moment (started, not over yet). */
    /**
     * Not seen for INACTIVE_AFTER (and not on vacation): his planets show yellow in the galaxy,
     * even his home planet can be invaded, and invaded planets take one slot and belong to the
     * conqueror at once. Logging in again ends it.
     */
    public function isInactive(\DateTimeImmutable $now): bool
    {
        return !$this->system && !$this->isDeactivated() && !$this->isOnVacation($now)
            && ($this->lastSeenAt ?? $this->createdAt) <= $now->modify('-'.self::INACTIVE_AFTER);
    }

    public function isOnVacation(\DateTimeImmutable $now): bool
    {
        return null !== $this->vacationSince && $this->vacationSince <= $now && $now < $this->vacationUntil;
    }

    /** Production, queues and research stand still from the start of the vacation on. */
    public function clockAt(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return null !== $this->vacationSince && $this->vacationSince < $now ? $this->vacationSince : $now;
    }

    public function startVacation(\DateTimeImmutable $since, \DateTimeImmutable $until, bool $forced = false, ?string $reason = null): void
    {
        $this->vacationSince = $since;
        $this->vacationUntil = $until;
        $this->vacationForced = $forced;
        $this->vacationReason = null === $reason ? null : mb_substr($reason, 0, 255);
    }

    /** Ends the vacation; returns how many seconds it lasted (for shifting all clocks). */
    public function endVacation(\DateTimeImmutable $at): int
    {
        $seconds = null === $this->vacationSince ? 0 : max(0, $at->getTimestamp() - $this->vacationSince->getTimestamp());
        $this->researchFinishesAt = $this->researchFinishesAt?->modify("+{$seconds} seconds");
        $this->vacationSince = $this->vacationUntil = $this->vacationReason = null;
        $this->vacationForced = false;

        return $seconds;
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

    /** Hero points now: the stored ones plus what recovered since the last crime (never above START). */
    public function getHeroPoints(?\DateTimeImmutable $now = null): float
    {
        if (null === $this->lastCrimeAt || $this->heroPoints >= \App\Game\HeroPoints::START) {
            return $this->heroPoints;
        }
        $days = max(0, (($now ?? \Symfony\Component\Clock\now())->getTimestamp() - $this->lastCrimeAt->getTimestamp()) / 86400);

        return min(\App\Game\HeroPoints::START, $this->heroPoints + $days * \App\Game\HeroPoints::RECOVERY_PER_DAY);
    }

    /** Hero points (positive) or crime points (negative); crime points restart the recovery. */
    public function addHeroPoints(float $points, ?\DateTimeImmutable $now = null): void
    {
        $now ??= \Symfony\Component\Clock\now();
        $this->heroPoints = $this->getHeroPoints($now) + $points;
        if ($points < 0) {
            $this->lastCrimeAt = $now;
        } elseif (null !== $this->lastCrimeAt) {
            // Keep recovering from now on, without counting the time before twice.
            $this->lastCrimeAt = $now;
        }
    }

    /** The chosen ticker – only while the player still belongs to it. */
    public function getBoardTicker(): ?Ticker
    {
        return true === $this->boardTicker?->isMember($this) ? $this->boardTicker : null;
    }

    public function setBoardTicker(?Ticker $ticker): void
    {
        $this->boardTicker = $ticker;
    }

    public function isCriminal(?\DateTimeImmutable $now = null): bool
    {
        return $this->getHeroPoints($now) < 0;
    }

    /** Ship speed of the life form – criminal guardians lose their double speed. */
    public function speedFactor(): float
    {
        return LifeForm::Guardian === $this->lifeForm && $this->isCriminal() ? 1.0 : $this->lifeForm->speedFactor();
    }

    /** Credit income of the population: the alliance takes its tax into its treasury. */
    public function earnCredits(float $amount): void
    {
        $tax = null === $this->alliance ? 0.0 : $this->alliance->collectTax($amount);
        $this->credits += $amount - $tax;
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

    /** How far the research is, 0 to 1; unknown duration (older research) counts as far along. */
    public function getResearchProgress(\DateTimeImmutable $now): float
    {
        if (null === $this->researchFinishesAt) {
            return 0.0;
        }
        if (null === $this->researchSeconds || $this->researchSeconds <= 0) {
            return 1.0;
        }
        $left = $this->researchFinishesAt->getTimestamp() - $now->getTimestamp();

        return max(0.0, min(1.0, 1 - $left / $this->researchSeconds));
    }

    public function startResearch(ResearchType $type, int $targetLevel, \DateTimeImmutable $finishesAt, ?Planet $planet = null, ?int $seconds = null): void
    {
        $this->researchType = $type;
        $this->researchTargetLevel = $targetLevel;
        $this->researchFinishesAt = $finishesAt;
        $this->researchSeconds = $seconds;
        $this->researchMailSent = false;
        $this->researchSlot = null === $planet ? null : \sprintf('%d:%d:%d', $planet->getGalaxy(), $planet->getSystem(), $planet->getPosition());
    }

    public function getResearchSlot(): ?string
    {
        return $this->researchSlot;
    }

    /** Whether the running research runs on a slot of this planet (fusions cover several slots). */
    public function isResearchingOn(Planet $planet): bool
    {
        if (!$this->isResearching() || null === $this->researchSlot) {
            return false;
        }
        [$g, $s, $p] = array_map('intval', explode(':', $this->researchSlot));

        return $planet->getGalaxy() === $g && $planet->getSystem() === $s && $planet->covers($p);
    }

    public function clearResearch(): void
    {
        $this->researchType = null;
        $this->researchTargetLevel = null;
        $this->researchFinishesAt = null;
        $this->researchSeconds = null;
        $this->researchSlot = null;
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

    public function isExtended(\DateTimeInterface $now): bool
    {
        return null !== $this->extendedUntil && $this->extendedUntil > $now;
    }

    public function getExtendedUntil(): ?\DateTimeImmutable
    {
        return $this->extendedUntil;
    }

    /** Extends the account up to the given moment (never shortens it). */
    public function extendUntil(\DateTimeImmutable $until): void
    {
        if (null === $this->extendedUntil || $until > $this->extendedUntil) {
            $this->extendedUntil = $until;
        }
    }

    public function getSubscriptionId(): ?string
    {
        return $this->subscriptionId;
    }

    public function getSubscriptionStatus(): ?string
    {
        return $this->subscriptionStatus;
    }

    public function setSubscription(?string $id, ?string $status): void
    {
        $this->subscriptionId = $id;
        $this->subscriptionStatus = $status;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): void
    {
        if (!\App\I18n\DisplayZone::isValid($timezone)) {
            throw new \InvalidArgumentException(\sprintf('Unknown time zone "%s".', $timezone));
        }
        $this->timezone = $timezone;
    }
}
