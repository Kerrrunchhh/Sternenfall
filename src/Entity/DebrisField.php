<?php

namespace App\Entity;

use App\Game\Resources;
use App\Repository\DebrisFieldRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wreckage in orbit after a battle: iron and titanium that salvagers can collect.
 */
#[ORM\Entity(repositoryClass: DebrisFieldRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_debris_slot', columns: ['galaxy', 'solar_system', 'position'])]
class DebrisField
{
    public const float SHARE = 0.3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $galaxy;

    #[ORM\Column(name: 'solar_system', type: Types::SMALLINT)]
    private int $system;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column]
    private float $iron = 0;

    #[ORM\Column]
    private float $titanium = 0;

    public function __construct(int $galaxy, int $system, int $position)
    {
        $this->galaxy = $galaxy;
        $this->system = $system;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGalaxy(): int
    {
        return $this->galaxy;
    }

    public function getSystem(): int
    {
        return $this->system;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getIron(): float
    {
        return $this->iron;
    }

    public function getTitanium(): float
    {
        return $this->titanium;
    }

    public function getResources(): Resources
    {
        return new Resources(iron: $this->iron, titanium: $this->titanium);
    }

    public function add(Resources $wreckage): void
    {
        $this->iron += floor($wreckage->iron);
        $this->titanium += floor($wreckage->titanium);
    }

    /** Takes up to $capacity units (iron first in proportion) and returns what was taken. */
    public function collect(float $capacity): Resources
    {
        $total = $this->iron + $this->titanium;
        if ($total <= 0 || $capacity <= 0) {
            return new Resources();
        }
        $share = min(1.0, $capacity / $total);
        $iron = floor($this->iron * $share);
        $titanium = floor($this->titanium * $share);
        $this->iron -= $iron;
        $this->titanium -= $titanium;

        return new Resources(iron: $iron, titanium: $titanium);
    }

    public function isEmpty(): bool
    {
        return $this->iron + $this->titanium < 1;
    }
}
