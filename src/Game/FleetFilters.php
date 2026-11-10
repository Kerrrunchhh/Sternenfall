<?php

namespace App\Game;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Named, coloured fleet filters a player saves for the fleet page. The rules are checked
 * here (only known kinds, operators, ship types), so nothing else ends up in the database.
 */
final readonly class FleetFilters
{
    public const int MAX_FILTERS = 20;
    public const int MAX_RULES = 20;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** Saves the filter under its name (an existing one with that name is replaced). */
    public function save(User $player, string $name, string $color, string $definition): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ('' === $name || mb_strlen($name) > 30) {
            throw new GameException('Der Filtername muss 1 bis 30 Zeichen lang sein.');
        }
        if (1 !== preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            throw new GameException('Bitte wähle eine Farbe.');
        }
        $data = json_decode($definition, true);
        if (!\is_array($data) || !\is_array($data['rules'] ?? null)) {
            throw new GameException('Der Filter konnte nicht gelesen werden.');
        }
        if (\count($data['rules']) > self::MAX_RULES) {
            throw new GameException(\sprintf('Ein Filter kann höchstens %d Regeln haben.', self::MAX_RULES));
        }

        $filter = [
            'name' => $name,
            'color' => strtolower($color),
            'alliance' => false !== ($data['alliance'] ?? true),
            'rules' => array_map(self::rule(...), array_values($data['rules'])),
        ];
        $filters = array_values(array_filter($player->getFleetFilters(), static fn (array $f): bool => $f['name'] !== $name));
        if (\count($filters) >= self::MAX_FILTERS) {
            throw new GameException(\sprintf('Du kannst höchstens %d Filter speichern.', self::MAX_FILTERS));
        }
        $filters[] = $filter;
        usort($filters, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        $player->setFleetFilters($filters);
        $this->em->flush();
    }

    public function delete(User $player, string $name): void
    {
        $player->setFleetFilters(array_filter($player->getFleetFilters(), static fn (array $f): bool => $f['name'] !== $name));
        $this->em->flush();
    }

    /** @return array{join: string, kind: string, text: string, op: string, count: int, ship: string} */
    private static function rule(mixed $rule): array
    {
        if (!\is_array($rule)) {
            throw new GameException('Der Filter konnte nicht gelesen werden.');
        }
        $kind = (string) ($rule['kind'] ?? '');
        if (!\in_array($kind, ['starts', 'ends', 'contains', 'ships'], true)) {
            throw new GameException('Unbekannte Filterregel.');
        }
        $ship = (string) ($rule['ship'] ?? '');
        if ('ships' === $kind && null === ShipType::tryFrom($ship)) {
            throw new GameException('Unbekannter Schiffstyp im Filter.');
        }

        return [
            'join' => 'or' === ($rule['join'] ?? 'and') ? 'or' : 'and',
            'kind' => $kind,
            'text' => mb_substr(trim((string) ($rule['text'] ?? '')), 0, 40),
            'op' => \in_array($rule['op'] ?? '', ['gte', 'lte', 'eq'], true) ? $rule['op'] : 'gte',
            'count' => max(0, min(1_000_000_000, (int) ($rule['count'] ?? 0))),
            'ship' => $ship,
        ];
    }
}
