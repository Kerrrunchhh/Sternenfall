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

    /** Hull left: what is gone stays gone – a unit with no hull left is destroyed. */
    public float $hull;
    /** Shield left in this round: it takes hits first and is full again every round (recharge()). */
    public float $shield;
    /** Hit points left: hull and the shield of this round. Setting it deals the difference as damage. */
    public float $hp {
        get => $this->hull + $this->shield;
        set(float $value) {
            $this->takeDamage($this->hull + $this->shield - $value);
        }
    }
    private readonly float $hullPerUnit;
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
        // Fixed values: the attacking side (also when intercepting or raiding) fights with its
        // attack, the defending side with its defense.
        $this->attack = self::ATTACKER === $side ? ShipStats::attack($type, $owner) : ShipStats::defense($type, $owner);
        $this->shieldPerUnit = ShipStats::shield($type, $owner);
        $this->hullPerUnit = $type->spec()->hull;
        $this->hull = $count * $this->hullPerUnit;
        $this->shield = $count * $this->shieldPerUnit;
    }

    /** Damage hits the shield first, the rest the hull. */
    public function takeDamage(float $damage): void
    {
        if ($damage <= 0) {
            return;
        }
        $absorbed = min($this->shield, $damage);
        $this->shield -= $absorbed;
        $this->hull = max(0.0, $this->hull - ($damage - $absorbed));
        if ($this->hull <= 1e-6) {
            $this->hull = 0.0;
            $this->shield = 0.0;
        }
    }

    /** A new round: the shields of the units still alive are full again. */
    public function recharge(): void
    {
        $this->shield = $this->alive() * $this->shieldPerUnit;
    }

    public function attackPerUnit(): float
    {
        return $this->attack;
    }

    /** Units still able to fight: as long as hull is left (a damaged unit counts as alive). */
    public function alive(): int
    {
        return $this->hull <= 1e-6 ? 0 : min($this->count, (int) ceil($this->hull / max(1e-9, $this->hullPerUnit) - 1e-9));
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
