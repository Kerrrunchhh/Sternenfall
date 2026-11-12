<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Once a day: how many ships of each type exist and how many resources lie on all planets (for the status page). */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_snapshot_day', columns: ['day'])]
class GameSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, int>   $ships     ship type => count (hangars and fleets, turrets included)
     * @param array<string, float> $resources resource type => amount on all planets
     */
    public function __construct(
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $day,
        #[ORM\Column(type: Types::JSON)]
        private array $ships,
        #[ORM\Column(type: Types::JSON)]
        private array $resources,
    ) {
    }

    public function getId(): ?int { return $this->id; }
    public function getDay(): \DateTimeImmutable { return $this->day; }

    /** @return array<string, int> */
    public function getShips(): array { return $this->ships; }

    /** @return array<string, float> */
    public function getResources(): array { return $this->resources; }
}
