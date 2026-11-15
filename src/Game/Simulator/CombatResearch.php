<?php

namespace App\Game\Simulator;

use App\Game\ResearchType;

/** The research that changes fighting values: weapons and shields (+4 % per level). */
final class CombatResearch
{
    /** @return list<ResearchType> */
    public static function types(): array
    {
        return array_values(array_filter(ResearchType::cases(), static fn (ResearchType $r): bool => \in_array($r->category(), ['Waffen', 'Schilde'], true)));
    }
}
