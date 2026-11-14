<?php

namespace App\Game;

use App\Entity\User;

/**
 * A battle of up to ROUNDS rounds; it ends early once a side has nothing left to hit. In
 * every round each unit fires once, in firing order; units sharing a place in the order
 * (same type on both sides) fire simultaneously. Hits go to the shield first and only the rest
 * to the hull; the shields of the surviving units are full again at the start of each round,
 * lost hull stays lost. A group aims at
 * the target groups of its priorities in turn (TargetGroup: fighters hunt invasion
 * ships and bombers, every class fights the others before its own); within a target
 * group the damage is spread by remaining hit points (hull + shield), and what is
 * left after destroying it goes on to the next. Weapon and shield research add 4 % per level (level 25 doubles a value).
 * Brecher deal triple damage to turrets, fighters to invasion ships and bombers. In a raid without bombers, transport
 * ships can't be hit.
 *
 * Pure calculation: give it the forces, read the survivors.
 */
final class Battle
{
    /** Bonus per level of weapon, shield and drive research: about level 25 doubles a value. */
    public const float RESEARCH_BONUS = 0.04;
    /** At most this many rounds; the shields are full again at the start of each. */
    public const int ROUNDS = 3;

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

        // The course of the battle for the replay: who fired at whom, how hard, and what it destroyed.
        $log = [];
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            foreach ($this->groups as $group) {
                $group->recharge();
            }
            $dealt = 0.0;
            foreach ($orders as $order) {
                // Everybody at this place fires with the units alive at the start of the step.
                $shots = [];
                foreach ($this->groups as $index => $group) {
                    // Units without weapons (invasion ships, colony ships …) don't fire.
                    if ($group->type->firingOrder() === $order && $group->alive() > 0 && $group->attackPerUnit() > 0) {
                        $shots[] = [$index, $group, $group->alive() * $group->attackPerUnit()];
                    }
                }
                $step = [];
                foreach ($shots as [$index, $shooter, $damage]) {
                    $before = array_map(static fn (BattleGroup $g): array => [$g->hp, $g->alive()], $this->groups);
                    $this->distribute($shooter, $damage, $protectTransports);
                    $hits = [];
                    foreach ($this->groups as $target => $group) {
                        if ($group->hp < $before[$target][0]) {
                            $hits[] = ['group' => $target, 'damage' => round($before[$target][0] - $group->hp, 1), 'destroyed' => $before[$target][1] - $group->alive()];
                            $dealt += $before[$target][0] - $group->hp;
                        }
                    }
                    $step[] = ['group' => $index, 'units' => $shooter->alive(), 'hits' => $hits];
                }
                if ([] !== $step) {
                    $log[] = ['round' => $round, 'order' => $order, 'shots' => $step];
                }
            }
            // Over when a side is wiped out – or when nobody can hurt anybody any more.
            if ($dealt <= 0 || !$this->hasTargets(BattleGroup::ATTACKER, $protectTransports) || !$this->hasTargets(BattleGroup::DEFENDER, $protectTransports)) {
                break;
            }
        }

        return new BattleResult($this->groups, $protectTransports, ['steps' => $log]);
    }

    /** Whether the side still has units the other side can hit. */
    private function hasTargets(string $side, bool $protectTransports): bool
    {
        foreach ($this->groups as $group) {
            if ($group->side === $side && $group->alive() > 0 && !($protectTransports && BattleGroup::DEFENDER === $side && $group->type->isTransport())) {
                return true;
            }
        }

        return false;
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
            // Specialities hit three times as hard (breakers turrets, fighters invaders): the same damage takes three times the hit points.
            $factor = TargetGroup::damageFactor($shooter->type, $priority);
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
