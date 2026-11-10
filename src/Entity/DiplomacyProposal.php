<?php

namespace App\Entity;

use App\Game\TreatyKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A proposal to start or end a relation. First the proposing alliance votes (founder and
 * commanders), then – where both sides must agree – the other alliance.
 */
#[ORM\Entity]
class DiplomacyProposal
{
    public const int MAX_MESSAGE = 2000;
    public const string STAGE_OWN = 'own';
    public const string STAGE_OTHER = 'other';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Alliance $proposer;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Alliance $target;

    #[ORM\Column(enumType: TreatyKind::class)]
    private TreatyKind $kind;

    /** False: start the relation; true: end the existing one. */
    #[ORM\Column]
    private bool $ending;

    #[ORM\Column(length: 10)]
    private string $stage = self::STAGE_OWN;

    /** The proposer's words: the declaration of war, the offer of a bond … */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /** @var array<string, bool> user id => yes/no of the proposing alliance */
    #[ORM\Column(type: Types::JSON)]
    private array $ownVotes = [];

    /** @var array<string, bool> user id => yes/no of the other alliance */
    #[ORM\Column(type: Types::JSON)]
    private array $otherVotes = [];

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Alliance $proposer, Alliance $target, TreatyKind $kind, bool $ending, User $createdBy, \DateTimeImmutable $createdAt, string $message = '')
    {
        $message = trim($message);
        $this->message = '' === $message ? null : mb_substr($message, 0, self::MAX_MESSAGE);
        $this->proposer = $proposer;
        $this->target = $target;
        $this->kind = $kind;
        $this->ending = $ending;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProposer(): Alliance
    {
        return $this->proposer;
    }

    public function getTarget(): Alliance
    {
        return $this->target;
    }

    public function getKind(): TreatyKind
    {
        return $this->kind;
    }

    public function isEnding(): bool
    {
        return $this->ending;
    }

    public function getMessage(): string
    {
        return $this->message ?? '';
    }

    /** The message as a quoted block for notifications ('' without message). */
    public function quotedMessage(): string
    {
        if (null === $this->message) {
            return '';
        }

        return "\n\nNachricht von „".$this->proposer->getName()."“:\n".implode("\n", array_map(static fn (string $l): string => '> '.$l, explode("\n", $this->message)));
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function toOtherSide(): void
    {
        $this->stage = self::STAGE_OTHER;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** The alliance whose leaders vote now. */
    public function votingAlliance(): Alliance
    {
        return self::STAGE_OWN === $this->stage ? $this->proposer : $this->target;
    }

    /** @return array<string, bool> votes of the current stage */
    public function votes(): array
    {
        return self::STAGE_OWN === $this->stage ? $this->ownVotes : $this->otherVotes;
    }

    public function vote(User $user, bool $yes): void
    {
        if (self::STAGE_OWN === $this->stage) {
            $this->ownVotes[(string) $user->getId()] = $yes;
        } else {
            $this->otherVotes[(string) $user->getId()] = $yes;
        }
    }

    /** "Bündnis", "Ende des Bündnisses" … */
    public function title(): string
    {
        if (!$this->ending) {
            return $this->kind->label();
        }

        return match ($this->kind) {
            TreatyKind::War => 'Frieden (Ende des Kriegs)',
            TreatyKind::Bond => 'Ende des Bündnisses',
            TreatyKind::Nap => 'Ende des Nichtangriffspakts',
        };
    }
}
