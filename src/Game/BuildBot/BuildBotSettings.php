<?php

namespace App\Game\BuildBot;

use App\Game\BuildingType;
use App\Game\Economy;
use App\Game\QueueKind;
use App\Game\ShipType;

/**
 * What the build bot puts into the queues when the player presses its button – one set of
 * settings per player, used on whichever planet it fills: up to three buildings with a target level, worked off in order, then
 * a default building; for the shipyard and the defense one unit type at a build rate, as
 * many as the planet can pay, optionally after clearing that queue.
 */
final readonly class BuildBotSettings
{
    public const int GOALS = 3;

    /**
     * @param list<array{building: BuildingType, level: int}>                       $goals
     * @param array<string, array{ship: ?ShipType, percent: int, clear: bool}> $queues QueueKind value (shipyard, defense) => setting
     */
    public function __construct(
        public array $goals = [],
        public ?BuildingType $default = null,
        public array $queues = [],
    ) {
    }

    /** The building to queue next: the first goal not reached yet (queued upgrades count), else the default. */
    public function nextBuilding(callable $plannedLevel, callable $buildable): ?BuildingType
    {
        foreach ($this->goals as ['building' => $building, 'level' => $level]) {
            if ($plannedLevel($building) < $level && $buildable($building)) {
                return $building;
            }
        }

        return null !== $this->default && $buildable($this->default) ? $this->default : null;
    }

    /** @return array{ship: ?ShipType, percent: int, clear: bool} */
    public function queue(QueueKind $kind): array
    {
        return $this->queues[$kind->value] ?? ['ship' => null, 'percent' => 100, 'clear' => false];
    }

    /** @param array<string, mixed>|null $data as stored on the planet */
    public static function fromArray(?array $data): self
    {
        $goals = [];
        foreach (\array_slice($data['goals'] ?? [], 0, self::GOALS) as $goal) {
            $building = BuildingType::tryFrom((string) ($goal['building'] ?? ''));
            $level = (int) ($goal['level'] ?? 0);
            if (null !== $building && $level > 0) {
                $goals[] = ['building' => $building, 'level' => min(999, $level)];
            }
        }
        $queues = [];
        foreach ([QueueKind::Shipyard, QueueKind::Defense] as $kind) {
            $q = $data['queues'][$kind->value] ?? [];
            $ship = ShipType::tryFrom((string) ($q['ship'] ?? ''));
            // Only units that belong in this queue.
            if (null !== $ship && QueueKind::forShip($ship) !== $kind) {
                $ship = null;
            }
            $percent = (int) ($q['percent'] ?? 100);
            $queues[$kind->value] = [
                'ship' => $ship,
                'percent' => \in_array($percent, Economy::BUILD_PERCENTS, true) ? $percent : 100,
                'clear' => (bool) ($q['clear'] ?? false),
            ];
        }

        return new self($goals, BuildingType::tryFrom((string) ($data['default'] ?? '')), $queues);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'goals' => array_map(static fn (array $g): array => ['building' => $g['building']->value, 'level' => $g['level']], $this->goals),
            'default' => $this->default?->value,
            'queues' => array_map(static fn (array $q): array => ['ship' => $q['ship']?->value, 'percent' => $q['percent'], 'clear' => $q['clear']], $this->queues),
        ];
    }
}
