<?php

namespace App\Entity;

use App\Repository\BattleReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A combat report anyone with its link can read for 30 days (to share it with other players).
 */
#[ORM\Entity(repositoryClass: BattleReportRepository::class)]
#[ORM\Index(name: 'idx_battle_report_created', columns: ['created_at'])]
class BattleReport
{
    public const int DAYS_AVAILABLE = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $hash;

    #[ORM\Column(length: 150)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * The report in the other languages (subject and body above are the German original).
     *
     * @var array<string, array{subject: string, body: string}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $translations = null;

    /**
     * What every participant lost (for refunds by an admin): user id => name and ships.
     *
     * @var array<int, array{name: string, ships: array<string, int>}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $losses = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annulledAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $annulReason = null;

    /**
     * Ships an admin gave back: list of user id, planet id, ships and time.
     *
     * @var list<array{user: int, name: string, planet: int, planetName: string, ships: array<string, int>, at: string}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $refunds = null;

    /** @param array<string, array{subject: string, body: string}> $translations */
    public function __construct(string $hash, string $subject, string $body, \DateTimeImmutable $createdAt, array $translations = [])
    {
        $this->hash = $hash;
        $this->subject = mb_substr($subject, 0, 150);
        $this->body = $body;
        $this->createdAt = $createdAt;
        $this->translations = array_map(static fn (array $text): array => ['subject' => mb_substr($text['subject'], 0, 150), 'body' => $text['body']], $translations) ?: null;
    }

    /** Subject in the reader's language (German if there is no translation). */
    public function subjectIn(string $locale): string
    {
        return $this->translations[$locale]['subject'] ?? $this->subject;
    }

    public function bodyIn(string $locale): string
    {
        return $this->translations[$locale]['body'] ?? $this->body;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->createdAt->modify('+'.self::DAYS_AVAILABLE.' days');
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->getExpiresAt();
    }

    /** @param array<int, array{name: string, ships: array<string, int>}> $losses */
    public function setLosses(array $losses): void
    {
        $this->losses = $losses ?: null;
    }

    /** @return array<int, array{name: string, ships: array<string, int>}> */
    public function getLosses(): array
    {
        return $this->losses ?? [];
    }

    public function isAnnulled(): bool
    {
        return null !== $this->annulledAt;
    }

    public function getAnnulledAt(): ?\DateTimeImmutable
    {
        return $this->annulledAt;
    }

    public function getAnnulReason(): ?string
    {
        return $this->annulReason;
    }

    public function annul(string $reason, \DateTimeImmutable $at): void
    {
        $this->annulledAt ??= $at;
        $this->annulReason = mb_substr(trim($reason), 0, 500) ?: $this->annulReason;
    }

    /** @param array<string, int> $ships */
    public function addRefund(int $userId, string $name, int $planetId, string $planetName, array $ships, \DateTimeImmutable $at): void
    {
        $this->refunds[] = ['user' => $userId, 'name' => $name, 'planet' => $planetId, 'planetName' => $planetName, 'ships' => $ships, 'at' => $at->format(\DATE_ATOM)];
    }

    /** @return list<array{user: int, name: string, planet: int, planetName: string, ships: array<string, int>, at: string}> */
    public function getRefunds(): array
    {
        return $this->refunds ?? [];
    }
}
