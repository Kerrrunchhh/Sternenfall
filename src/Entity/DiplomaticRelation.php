<?php

namespace App\Entity;

use App\Game\TreatyKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A bond, non-aggression pact or war between two alliances (at most one per pair). */
#[ORM\Entity]
class DiplomaticRelation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The alliance that proposed or declared it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Alliance $first;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Alliance $second;

    #[ORM\Column(enumType: TreatyKind::class)]
    private TreatyKind $kind;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $since;

    public function __construct(Alliance $first, Alliance $second, TreatyKind $kind, \DateTimeImmutable $since)
    {
        $this->first = $first;
        $this->second = $second;
        $this->kind = $kind;
        $this->since = $since;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirst(): Alliance
    {
        return $this->first;
    }

    public function getSecond(): Alliance
    {
        return $this->second;
    }

    public function getKind(): TreatyKind
    {
        return $this->kind;
    }

    public function getSince(): \DateTimeImmutable
    {
        return $this->since;
    }

    public function involves(Alliance $alliance): bool
    {
        return $this->first->getId() === $alliance->getId() || $this->second->getId() === $alliance->getId();
    }

    /** The other alliance, seen from one of the two. */
    public function other(Alliance $alliance): Alliance
    {
        return $this->first->getId() === $alliance->getId() ? $this->second : $this->first;
    }
}
