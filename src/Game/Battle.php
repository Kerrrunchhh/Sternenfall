<?php

namespace App\Game;

use App\Entity\User;

/**
 * One combat round. Every unit fires once, in firing order; units sharing a
 * place in the order (same type on both sides) fire simultaneously. Damage of a
 * group is spread over the living enemy groups in proportion to their remaining
 * hit points (hull + shield). Weapon and shield research add 10 % per level.
 * Brecher deal triple damage to turrets. In a raid without bombers, transport
 * ships can't be hit.
 *
 * Pure calculation: give it the forces, read the survivors.
 */
final class Battle
{
    public const float RESEARCH_BONUS = 0.1;
    public const float BREAKER_VS_TURRET = 3.0;

    /** @var list<BattleGroup> */
    private array $groups = [];
    private bool $spareTransports = false;

    /** @param array<string, int> $ships */
    public function add(string $side, User $owner, array $ships, ?string $source = null): void
    {
        foreach ($ships as $type => $count) {
            if ($count > 0) {
                $this->groups[] = new BattleGroup($side, $owner, ShipType::from($type), $count, $source);
            }
        }
    }

    /** Raid on a transport fleet: without a bomber on the attacking side, transports are no target. */
    public function spareTransportsWithoutBomber(): void
    {
        $this->spareTransports = true;
    }

    public function fight(): BattleResult
    {
        $attackerHasBomber = [] !== array_filter($this->groups, static fn (BattleGroup $g): bool => BattleGroup::ATTACKER === $g->side && ShipType::HeavyBomber === $g->type);
        $protectTransports = $this->spareTransports && !$attackerHasBomber;

        $orders = array_unique(array_map(static fn (BattleGroup $g): int => $g->type->firingOrder(), $this->groups));
        sort($orders);

        foreach ($orders as $order) {
            // Everybody at this place fires with the units alive at the start of the step.
            $shots = [];
            foreach ($this->groups as $group) {
                if ($group->type->firingOrder() === $order && $group->alive() > 0) {
                    $shots[] = [$group, $group->alive() * $group->attackPerUnit()];
                }
            }
            foreach ($shots as [$shooter, $damage]) {
                $this->distribute($shooter, $damage, $protectTransports);
            }
        }

        return new BattleResult($this->groups, $protectTransports);
    }

    private function distribute(BattleGroup $shooter, float $damage, bool $protectTransports): void
    {
        if ($damage <= 0) {
            return;
        }
        $targets = array_values(array_filter(
            $this->groups,
            static fn (BattleGroup $g): bool => $g->side !== $shooter->side && $g->hp > 0
                && !($protectTransports && BattleGroup::DEFENDER === $g->side && $g->type->isTransport()),
        ));
        $total = array_sum(array_map(static fn (BattleGroup $g): float => $g->hp, $targets));
        if ($total <= 0) {
            return;
        }
        foreach ($targets as $target) {
            $share = $damage * $target->hp / $total;
            if (ShipType::Brecher === $shooter->type && $target->type->isStationary()) {
                $share *= self::BREAKER_VS_TURRET;
            }
            $target->hp = max(0.0, $target->hp - $share);
        }
    }
}
