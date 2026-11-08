<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a player's probe saw of a foreign fleet in flight. Without it, the
 * composition of a fleet on the radar is unknown.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_fleet_intel', columns: ['user_id', 'fleet_id'])]
class FleetIntel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Fleet $fleet;

    /** @var array<string, int> ship type value => count, as seen */
    #[ORM\Column(type: Types::JSON)]
    private array $ships;

    #[ORM\Column]
    private float $cargo;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $scoutedAt;

    /** @param array<string, int> $ships */
    public function __construct(User $user, Fleet $fleet, array $ships, float $cargo, \DateTimeImmutable $scoutedAt)
    {
        $this->user = $user;
        $this->fleet = $fleet;
        $this->update($ships, $cargo, $scoutedAt);
    }

    /** @param array<string, int> $ships */
    public function update(array $ships, float $cargo, \DateTimeImmutable $scoutedAt): void
    {
        $this->ships = $ships;
        $this->cargo = $cargo;
        $this->scoutedAt = $scoutedAt;
    }

    public function getFleet(): Fleet
    {
        return $this->fleet;
    }

    /** @return list<array{type: \App\Game\ShipType, count: int}> */
    public function getShipList(): array
    {
        $list = [];
        foreach (\App\Game\ShipType::cases() as $type) {
            if (($this->ships[$type->value] ?? 0) > 0) {
                $list[] = ['type' => $type, 'count' => $this->ships[$type->value]];
            }
        }

        return $list;
    }

    public function getCargo(): float
    {
        return $this->cargo;
    }

    public function getScoutedAt(): \DateTimeImmutable
    {
        return $this->scoutedAt;
    }
}
