<?php

namespace App\I18n;

use App\Game\Resources;
use App\Game\ResourceType;
use App\Game\ShipType;
use App\Game\TradeDeal;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/** Translated lists of goods for messages ("Eisen 1.000, Wasser 200"). */
final class Goods
{
    /** @param array<string, float> $goods good (resource type value or "fusionator") => amount */
    public static function summary(array $goods): TranslatableInterface
    {
        return new Lines(array_map(static fn (string $good, float $amount): TranslatableInterface => t('{good} {amount}', ['{good}' => t(TradeDeal::label($good)), '{amount}' => new Num($amount)]), array_keys($goods), $goods), ', ');
    }

    /**
     * "3 × Frachter, 1 × Sonde" from a ship map (type value => count) or a ship list.
     *
     * @param array<string, int>|list<array{type: ShipType, count: int}> $ships
     */
    public static function ships(array $ships, string $separator = ', '): TranslatableInterface
    {
        $parts = [];
        foreach ($ships as $key => $entry) {
            [$type, $count] = \is_array($entry) ? [$entry['type'], $entry['count']] : [ShipType::from((string) $key), $entry];
            if ($count > 0) {
                $parts[] = t('{count} × {ship}', ['{count}' => new Num($count), '{ship}' => t($type->label())]);
            }
        }

        return new Lines($parts, $separator);
    }

    /** One line per resource type, indented: "  Eisen: 1.000". */
    public static function lines(Resources $resources): TranslatableInterface
    {
        return new Lines(array_map(static fn (ResourceType $type): TranslatableInterface => t('  {resource}: {amount}', ['{resource}' => t($type->label()), '{amount}' => new Num($resources->get($type))]), ResourceType::cases()));
    }
}
