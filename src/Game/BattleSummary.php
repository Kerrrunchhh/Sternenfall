<?php

namespace App\Game;

use App\Entity\BattleReport;

/**
 * Adds up many shared battle reports (a war, a long raid): what attackers and defenders lost
 * in ships, turrets and plundered resources, who took part and how much combat experience
 * each player got. Annulled reports are listed but not counted.
 */
final class BattleSummary
{
    public const int MAX_REPORTS = 200;

    /** @var array<string, array{ships: array<string, int>, turrets: array<string, int>, loot: Resources, experience: float}> */
    public array $sides = [];
    /** @var array<string, array{name: string, sides: array<string, true>, battles: int, ships: array<string, int>, turrets: array<string, int>, loot: Resources, experience: float}> */
    public array $players = [];
    /** @var list<BattleReport> counted reports, oldest first */
    public array $counted = [];
    /** @var list<BattleReport> */
    public array $annulled = [];
    /** Counted reports from before plunder was stored with the report: their loot is missing. */
    public int $withoutLoot = 0;

    /** @param list<BattleReport> $reports */
    public function __construct(array $reports)
    {
        foreach ([BattleGroup::ATTACKER, BattleGroup::DEFENDER] as $side) {
            $this->sides[$side] = ['ships' => [], 'turrets' => [], 'loot' => new Resources(), 'experience' => 0.0];
        }
        usort($reports, static fn (BattleReport $a, BattleReport $b): int => $a->getCreatedAt() <=> $b->getCreatedAt());
        foreach ($reports as $report) {
            $replay = $report->getReplay();
            if ($report->isAnnulled()) {
                $this->annulled[] = $report;
                continue;
            }
            if (null === $replay) {
                continue;
            }
            $this->counted[] = $report;
            $this->add($replay);
        }
        // Most experienced first.
        uasort($this->players, static fn (array $a, array $b): int => $b['experience'] <=> $a['experience']);
    }

    /** @param array<string, mixed> $replay */
    private function add(array $replay): void
    {
        $seen = [];
        foreach ($replay['groups'] ?? [] as $group) {
            $type = ShipType::tryFrom((string) $group['type']);
            $side = (string) $group['side'];
            $player = &$this->player($group['ownerId'] ?? null, (string) $group['owner']);
            $player['sides'][$side] = true;
            $seen[self::key($group['ownerId'] ?? null, (string) $group['owner'])] = true;
            $lost = max(0, (int) $group['count'] - (int) $group['alive']);
            if (null === $type || 0 === $lost) {
                unset($player);
                continue;
            }
            $kind = $type->isStationary() ? 'turrets' : 'ships';
            $this->sides[$side][$kind][$type->value] = ($this->sides[$side][$kind][$type->value] ?? 0) + $lost;
            $player[$kind][$type->value] = ($player[$kind][$type->value] ?? 0) + $lost;
            unset($player);
        }
        foreach (array_keys($seen) as $key) {
            ++$this->players[$key]['battles'];
        }

        foreach ($replay['experience'] ?? [] as $entry) {
            $side = (string) ($entry['side'] ?? BattleGroup::ATTACKER);
            $player = &$this->player($entry['ownerId'] ?? null, (string) $entry['owner']);
            $player['experience'] += (float) $entry['experience'];
            $this->sides[$side]['experience'] += (float) $entry['experience'];
            unset($player);
        }

        if (!\array_key_exists('loot', $replay)) {
            ++$this->withoutLoot;

            return;
        }
        foreach ($replay['loot'] as $entry) {
            $resources = Resources::fromArray($entry['resources'] ?? []);
            $player = &$this->player($entry['ownerId'] ?? null, (string) $entry['owner']);
            $player['loot'] = $player['loot']->add($resources);
            unset($player);
            // Plunder is what the attackers carried off – and what the defenders lost.
            $this->sides[BattleGroup::ATTACKER]['loot'] = $this->sides[BattleGroup::ATTACKER]['loot']->add($resources);
        }
    }

    /** @return array{name: string, sides: array<string, true>, battles: int, ships: array<string, int>, turrets: array<string, int>, loot: Resources, experience: float} */
    private function &player(?int $id, string $name): array
    {
        $key = self::key($id, $name);
        $this->players[$key] ??= ['name' => $name, 'sides' => [], 'battles' => 0, 'ships' => [], 'turrets' => [], 'loot' => new Resources(), 'experience' => 0.0];

        return $this->players[$key];
    }

    /** Players by id (names can change), the rest – e.g. deleted ones – by name. */
    private static function key(?int $id, string $name): string
    {
        return null !== $id ? 'id:'.$id : 'name:'.$name;
    }
}
