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

    public function __construct(
        public readonly string $side,
        public readonly User $owner,
        public readonly ShipType $type,
        public readonly int $count,
        /** Where the units come from, e.g. "hangar" or a fleet id; used to write losses back. */
        public readonly ?string $source = null,
    ) {
        $spec = $type->spec();
        $weapon = $type->weaponResearch();
        $shield = $type->shieldResearch();
        $this->attack = $spec->attack * (1 + Battle::RESEARCH_BONUS * (null === $weapon ? 0 : $owner->getResearchLevel($weapon)));
        $shieldPoints = $spec->shield * (1 + Battle::RESEARCH_BONUS * (null === $shield ? 0 : $owner->getResearchLevel($shield)));
        $this->hpPerUnit = $spec->hull + $shieldPoints;
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
}
