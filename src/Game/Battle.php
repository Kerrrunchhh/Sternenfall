<?php

namespace App\Game;

use App\Entity\User;

/**
 * One combat round. Every unit fires once, in firing order; units sharing a
 * place in the order (same type on both sides) fire simultaneously. A group aims at
 * the target groups of its priorities in turn (TargetGroup: fighters hunt invasion
 * ships and bombers, every class fights the others before its own); within a target
 * group the damage is spread by remaining hit points (hull + shield), and what is
 * left after destroying it goes on to the next. Weapon and shield research add 4 % per level (level 25 doubles a value).
 * Brecher deal triple damage to turrets. In a raid without bombers, transport
 * ships can't be hit.
 *
 * Pure calculation: give it the forces, read the survivors.
 */
final class Battle
{
    /** Bonus per level of weapon, shield and drive research: about level 25 doubles a value. */
    public const float RESEARCH_BONUS = 0.04;
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

    /**
     * The shooter's damage goes to the first target group of its priorities with living enemies
     * (TargetGroup), spread by hit points; once that group is destroyed, the rest goes on to the next.
     */
    private function distribute(BattleGroup $shooter, float $damage, bool $protectTransports): void
    {
        foreach (TargetGroup::prioritiesOf($shooter->type) as $priority) {
            if ($damage <= 1e-9) {
                return;
            }
            $targets = array_values(array_filter(
                $this->groups,
                static fn (BattleGroup $g): bool => $g->side !== $shooter->side && $g->hp > 0 && TargetGroup::of($g->type) === $priority
                    && !($protectTransports && BattleGroup::DEFENDER === $g->side && $g->type->isTransport()),
            ));
            $total = array_sum(array_map(static fn (BattleGroup $g): float => $g->hp, $targets));
            if ($total <= 0) {
                continue;
            }
            // Brecher hit turrets three times as hard: the same damage destroys three times the hit points.
            $factor = ShipType::Brecher === $shooter->type && TargetGroup::Turrets === $priority ? self::BREAKER_VS_TURRET : 1.0;
            $needed = $total / $factor;
            if ($damage >= $needed) {
                foreach ($targets as $target) {
                    $target->hp = 0.0;
                }
                $damage -= $needed;
                continue;
            }
            foreach ($targets as $target) {
                $target->hp = max(0.0, $target->hp - $damage * $factor * $target->hp / $total);
            }

            return;
        }
    }
}
