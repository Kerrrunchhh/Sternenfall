<?php

namespace App\Entity;

use App\Game\PlanetType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a player found out by sending a probe to a slot: its planet type.
 * Planet types of foreign slots stay hidden until then.
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

    public function __construct(User $user, int $galaxy, int $system, int $position, PlanetType $type, \DateTimeImmutable $scoutedAt)
    {
        $this->user = $user;
        $this->galaxy = $galaxy;
        $this->system = $system;
        $this->position = $position;
        $this->type = $type;
        $this->scoutedAt = $scoutedAt;
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
}
