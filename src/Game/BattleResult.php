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
    public function report(): string
    {
        $lines = [];
        foreach ([BattleGroup::ATTACKER => 'Angreifer', BattleGroup::DEFENDER => 'Verteidiger'] as $side => $title) {
            $lines[] = $title.':';
            $groups = $this->side($side);
            usort($groups, static fn (BattleGroup $a, BattleGroup $b): int => [$a->owner->getUsername(), $a->type->firingOrder()] <=> [$b->owner->getUsername(), $b->type->firingOrder()]);
            foreach ($groups as $group) {
                $lines[] = \sprintf(
                    '  %s – %s: %s → %s%s',
                    $group->owner->getUsername(),
                    $group->type->label(),
                    number_format($group->count, 0, ',', '.'),
                    number_format($group->alive(), 0, ',', '.'),
                    $this->transportsSpared && BattleGroup::DEFENDER === $side && $group->type->isTransport() ? ' (verschont)' : '',
                );
            }
            if ([] === $groups) {
                $lines[] = '  keine Einheiten';
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }
}
