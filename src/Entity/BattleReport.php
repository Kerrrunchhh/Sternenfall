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
}
