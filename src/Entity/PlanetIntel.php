<?php

namespace App\Entity;

use App\Game\PlanetType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a player found out by sending a probe to a slot: its planet type and the
 * debris field drifting there. Both stay hidden from him until then (battles he
 * fought there and salvaging also tell him about the debris).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_planet_intel', columns: ['user_id', 'galaxy', 'solar_system', 'position'])]
class PlanetIntel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $galaxy;

    #[ORM\Column(name: 'solar_system', type: Types::SMALLINT)]
    private int $system;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(enumType: PlanetType::class)]
    private PlanetType $type;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $scoutedAt;

    /** Debris seen there (null: none seen). */
    #[ORM\Column(nullable: true)]
    private ?float $debrisIron = null;

    #[ORM\Column(nullable: true)]
    private ?float $debrisTitanium = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $debrisSeenAt = null;

    public function __construct(User $user, int $galaxy, int $system, int $position, PlanetType $type, \DateTimeImmutable $scoutedAt)
    {
        $this->user = $user;
        $this->galaxy = $galaxy;
        $this->system = $system;
        $this->position = $position;
        $this->type = $type;
        $this->scoutedAt = $scoutedAt;
    }

    public function isAt(User $user, int $galaxy, int $system, int $position): bool
    {
        return $this->user->isSameAs($user) && $this->galaxy === $galaxy && $this->system === $system && $this->position === $position;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getType(): PlanetType
    {
        return $this->type;
    }

    public function refresh(\DateTimeImmutable $at): void
    {
        $this->scoutedAt = $at;
    }

    /** Remembers the debris seen at the slot, or that there is none. */
    public function seeDebris(?float $iron, ?float $titanium, \DateTimeImmutable $at): void
    {
        $none = null === $iron || ($iron + (float) $titanium) < 1;
        $this->debrisIron = $none ? null : $iron;
        $this->debrisTitanium = $none ? null : $titanium;
        $this->debrisSeenAt = $none ? null : $at;
    }

    public function hasDebris(): bool
    {
        return null !== $this->debrisIron;
    }

    public function getDebrisIron(): float
    {
        return $this->debrisIron ?? 0.0;
    }

    public function getDebrisTitanium(): float
    {
        return $this->debrisTitanium ?? 0.0;
    }

    public function getDebrisSeenAt(): ?\DateTimeImmutable
    {
        return $this->debrisSeenAt;
    }
}
