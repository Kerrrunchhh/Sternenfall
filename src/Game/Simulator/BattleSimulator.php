<?php

namespace App\Game\Simulator;

use App\Entity\User;
use App\Game\Battle;
use App\Game\BattleGroup;
use App\Game\BattleResult;
use App\Game\ResearchType;
use App\Game\Resources;
use App\Game\ShipType;

/**
 * Battle simulator: the same calculation as a real attack on a planet, with forces and combat
 * research typed in by the player. Nothing is stored, nobody real takes part.
 */
final class BattleSimulator
{
    /** More units than this per type are cut off (the calculation runs per type, but keep inputs sane). */
    public const int MAX_UNITS = 100_000_000;
    public const int MAX_LEVEL = 100;

    /**
     * @param array<string, int> $attackerShips
     * @param array<string, int> $attackerResearch
     * @param array<string, int> $defenderShips    ships and turrets on the planet, other players' fleets included
     * @param array<string, int> $defenderResearch
     * @param string             $attackerName     how the sides are called in the result
     */
    public function simulate(array $attackerShips, array $attackerResearch, array $defenderShips, array $defenderResearch, string $attackerName = 'Angreifer', string $defenderName = 'Verteidiger'): BattleResult
    {
        $battle = new Battle();
        // Turrets can't fly: they only defend.
        $battle->add(BattleGroup::ATTACKER, self::player($attackerName, -1, $attackerResearch), array_filter(self::ships($attackerShips), static fn (int $n, string $type): bool => !ShipType::from($type)->isStationary(), ARRAY_FILTER_USE_BOTH), 'attacker');
        $battle->add(BattleGroup::DEFENDER, self::player($defenderName, -2, $defenderResearch), self::ships($defenderShips), 'hangar');

        return $battle->fight();
    }

    /**
     * Iron and titanium (base cost) of what one side lost.
     */
    public static function lostValue(BattleResult $result, string $side): Resources
    {
        $value = new Resources();
        foreach ($result->side($side) as $group) {
            $cost = $group->type->spec()->cost;
            $value = $value->add(new Resources(iron: $cost->iron, titanium: $cost->titanium)->multiply($group->lost()));
        }

        return $value;
    }

    /** @param array<string, int> $research */
    private static function player(string $name, int $id, array $research): User
    {
        $player = new User($name);
        // Negative ids: never a real player.
        (new \ReflectionProperty(User::class, 'id'))->setValue($player, $id);
        foreach (CombatResearch::types() as $type) {
            $player->setResearchLevel($type, max(0, min(self::MAX_LEVEL, (int) ($research[$type->value] ?? 0))));
        }

        return $player;
    }

    /**
     * Known types with a positive count only.
     *
     * @param array<string, int> $ships
     *
     * @return array<string, int>
     */
    private static function ships(array $ships): array
    {
        $clean = [];
        foreach ($ships as $type => $count) {
            if (null !== ShipType::tryFrom((string) $type) && $count > 0) {
                $clean[(string) $type] = min(self::MAX_UNITS, (int) $count);
            }
        }

        return $clean;
    }
}
