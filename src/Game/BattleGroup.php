<?php

namespace App\Game;

use App\Entity\User;

/**
 * Units of one type, one owner and one source (hangar or a fleet) in a battle.
 */
final class BattleGroup
{
    public const string ATTACKER = 'attacker';
    public const string DEFENDER = 'defender';

    public float $hp;
    private readonly float $hpPerUnit;
    private readonly float $attack;
    /** Shield of one unit, research included. */
    public readonly float $shieldPerUnit;

    public function __construct(
        public readonly string $side,
        public readonly User $owner,
        public readonly ShipType $type,
        public readonly int $count,
        /** Where the units come from, e.g. "hangar" or a fleet id; used to write losses back. */
        public readonly ?string $source = null,
    ) {
        $this->attack = ShipStats::attack($type, $owner);
        $this->shieldPerUnit = ShipStats::shield($type, $owner);
        $this->hpPerUnit = $type->spec()->hull + $this->shieldPerUnit;
        $this->hp = $count * $this->hpPerUnit;
    }

    public function attackPerUnit(): float
    {
        return $this->attack;
    }

    /** Units still able to fight (a partially damaged unit counts as alive). */
    public function alive(): int
    {
        return $this->hp <= 1e-6 ? 0 : min($this->count, (int) ceil($this->hp / $this->hpPerUnit - 1e-9));
    }

    public function lost(): int
    {
        return $this->count - $this->alive();
    }

    /** Shield points (research included) of the units destroyed in this group. */
    public function destroyedShield(): float
    {
        return $this->lost() * $this->shieldPerUnit;
    }

    /** Weight of the group for sharing combat experience: build time of all its units. */
    public function buildWeight(): float
    {
        return $this->count * Economy::baseShipHours($this->type);
    }
}
