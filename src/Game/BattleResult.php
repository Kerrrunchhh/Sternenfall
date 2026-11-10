<?php

namespace App\Game;

use App\Entity\User;

final readonly class BattleResult
{
    /** @param list<BattleGroup> $groups */
    public function __construct(
        public array $groups,
        public bool $transportsSpared,
    ) {
    }

    /** @return list<BattleGroup> */
    public function side(string $side): array
    {
        return array_values(array_filter($this->groups, static fn (BattleGroup $g): bool => $g->side === $side));
    }

    /** No defending unit is left (spared transports don't count as defending). */
    public function attackerWon(): bool
    {
        foreach ($this->side(BattleGroup::DEFENDER) as $group) {
            if ($group->alive() > 0 && !($this->transportsSpared && $group->type->isTransport())) {
                return false;
            }
        }

        return [] !== array_filter($this->side(BattleGroup::ATTACKER), static fn (BattleGroup $g): bool => $g->alive() > 0);
    }

    /**
     * Surviving ships of one source.
     *
     * @return array<string, int>
     */
    public function survivors(string $side, ?string $source): array
    {
        $ships = [];
        foreach ($this->side($side) as $group) {
            if ($group->source === $source && $group->alive() > 0) {
                $ships[$group->type->value] = ($ships[$group->type->value] ?? 0) + $group->alive();
            }
        }

        return $ships;
    }

    /** Combined attack strength of the units of one side that are still alive (research included). */
    public function attackPower(string $side): float
    {
        return array_sum(array_map(static fn (BattleGroup $g): float => $g->alive() * $g->attackPerUnit(), $this->side($side)));
    }

    public function survivingCount(string $side, ShipType $type): int
    {
        return array_sum(array_map(static fn (BattleGroup $g): int => $g->type === $type ? $g->alive() : 0, $this->side($side)));
    }

    /** Iron and titanium of everything destroyed (base cost). */
    public function destroyedValue(): Resources
    {
        $value = new Resources();
        foreach ($this->groups as $group) {
            $cost = $group->type->spec()->cost;
            $value = $value->add(new Resources(iron: $cost->iron, titanium: $cost->titanium)->multiply($group->lost()));
        }

        return $value;
    }

    /**
     * Combat experience: the shield points (research included) destroyed on one side go to
     * the players of the other side, shared by the build time of the units each brought.
     * Transports spared in a raid did not fight and get nothing.
     *
     * @return list<array{user: User, experience: float}>
     */
    public function experience(): array
    {
        $shares = [];
        foreach ([BattleGroup::ATTACKER => BattleGroup::DEFENDER, BattleGroup::DEFENDER => BattleGroup::ATTACKER] as $side => $enemy) {
            $pool = array_sum(array_map(static fn (BattleGroup $g): float => $g->destroyedShield(), $this->side($enemy)));
            $weights = [];
            foreach ($this->side($side) as $group) {
                if ($this->transportsSpared && BattleGroup::DEFENDER === $side && $group->type->isTransport()) {
                    continue;
                }
                $key = $group->owner->getId() ?? spl_object_id($group->owner);
                $weights[$key] = ['user' => $group->owner, 'weight' => ($weights[$key]['weight'] ?? 0.0) + $group->buildWeight()];
            }
            $total = array_sum(array_column($weights, 'weight'));
            if ($pool <= 0 || $total <= 0) {
                continue;
            }
            foreach ($weights as $entry) {
                $shares[] = ['user' => $entry['user'], 'experience' => $pool * $entry['weight'] / $total];
            }
        }

        return $shares;
    }

    /** @return list<User> everybody who took part */
    public function participants(): array
    {
        $users = [];
        foreach ($this->groups as $group) {
            $users[$group->owner->getId() ?? spl_object_id($group->owner)] = $group->owner;
        }

        return array_values($users);
    }

    /** Human readable report of both sides. */
    /**
     * Report of both sides, grouped by player. Seen by a participant ($viewer), his own
     * units are marked "Du" and those of his alliance "Allianz"; without a viewer (shared
     * link, newspaper) it is neutral.
     */
    public function report(?User $viewer = null): string
    {
        $lines = [];
        foreach ([BattleGroup::ATTACKER => 'Angreifer', BattleGroup::DEFENDER => 'Verteidiger'] as $side => $title) {
            $lines[] = $title.':';
            $byOwner = [];
            foreach ($this->side($side) as $group) {
                $byOwner[$group->owner->getId() ?? spl_object_id($group->owner)][] = $group;
            }
            $rank = static function (User $owner) use ($viewer): int {
                if (null === $viewer) {
                    return 2;
                }

                return $owner->isSameAs($viewer) ? 0 : ($owner->isAllyOf($viewer) ? 1 : 2);
            };
            uasort($byOwner, static fn (array $a, array $b): int => [$rank($a[0]->owner), mb_strtolower($a[0]->owner->getUsername())] <=> [$rank($b[0]->owner), mb_strtolower($b[0]->owner->getUsername())]);
            foreach ($byOwner as $groups) {
                $owner = $groups[0]->owner;
                $tag = null !== $owner->getAlliance() ? ' ['.$owner->getAlliance()->getTag().']' : '';
                $lines[] = '  '.match ($rank($owner)) {
                    0 => 'Du ('.$owner->getUsername().')',
                    1 => $owner->getUsername().$tag.' · Allianz',
                    default => $owner->getUsername().$tag,
                }.':';
                // Units of one type from several fleets of a player are counted together.
                $types = [];
                foreach ($groups as $group) {
                    $key = $group->type->value;
                    $types[$key] ??= ['type' => $group->type, 'count' => 0, 'alive' => 0];
                    $types[$key]['count'] += $group->count;
                    $types[$key]['alive'] += $group->alive();
                }
                usort($types, static fn (array $a, array $b): int => $a['type']->firingOrder() <=> $b['type']->firingOrder());
                foreach ($types as $t) {
                    $lost = $t['count'] - $t['alive'];
                    $lines[] = \sprintf(
                        '    %s: %s → %s%s%s',
                        $t['type']->label(),
                        number_format($t['count'], 0, ',', '.'),
                        number_format($t['alive'], 0, ',', '.'),
                        $lost > 0 ? ' (−'.number_format($lost, 0, ',', '.').')' : '',
                        $this->transportsSpared && BattleGroup::DEFENDER === $side && $t['type']->isTransport() ? ' (verschont)' : '',
                    );
                }
            }
            if ([] === $byOwner) {
                $lines[] = '  keine Einheiten';
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }
}
