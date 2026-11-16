<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A poll the admins send to all players: a question, 2 to 10 answers, one or several to tick,
 * running until endsAt. Players see the result only when it is over; admins see it all along.
 */
#[ORM\Entity(repositoryClass: \App\Repository\PollRepository::class)]
class Poll
{
    public const int MAX_QUESTION = 500;
    public const int MAX_OPTION = 150;
    public const int MIN_OPTIONS = 2;
    public const int MAX_OPTIONS = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_QUESTION)]
    private string $question;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $options;

    /** The English version: question and answers in the same order. */
    #[ORM\Column(length: self::MAX_QUESTION, nullable: true)]
    private ?string $questionEn = null;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $optionsEn = null;

    /** Several answers may be ticked. */
    #[ORM\Column]
    private bool $multiple;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $endsAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy;

    /** @param list<string> $options */
    public function __construct(string $question, array $options, bool $multiple, \DateTimeImmutable $createdAt, \DateTimeImmutable $endsAt, ?User $createdBy)
    {
        $this->question = $question;
        $this->options = array_values($options);
        $this->multiple = $multiple;
        $this->createdAt = $createdAt;
        $this->endsAt = $endsAt;
        $this->createdBy = $createdBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    /** @param list<string> $options answers in the same order as the German ones */
    public function translate(string $question, array $options): void
    {
        $this->questionEn = $question;
        $this->optionsEn = array_values($options);
    }

    /** The question in the reader's language (German if there is no English version). */
    public function questionIn(string $locale): string
    {
        return 'en' === $locale && null !== $this->questionEn ? $this->questionEn : $this->question;
    }

    /** @return list<string> the answers in the reader's language */
    public function optionsIn(string $locale): array
    {
        return 'en' === $locale && null !== $this->optionsEn && \count($this->optionsEn) === \count($this->options) ? $this->optionsEn : $this->options;
    }

    /** @return list<string> */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function isRunning(\DateTimeInterface $now): bool
    {
        return $now < $this->endsAt;
    }

    /** Ends it now (e.g. an admin closes it early). */
    public function close(\DateTimeImmutable $now): void
    {
        if ($now < $this->endsAt) {
            $this->endsAt = $now;
        }
    }
}
