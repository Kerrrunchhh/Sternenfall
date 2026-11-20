<?php

namespace App\Game;

/**
 * What a plundering fleet takes: evenly from every resource (the same share of each), or – if the
 * player set an order for the attack or raid – the first resource as far as it goes, then the next.
 */
final class LootOrder
{
    /**
     * A complete order of all resources from the form, or null (evenly) if it isn't one.
     *
     * @param list<mixed> $values resource values, first = taken first
     *
     * @return list<ResourceType>|null
     */
    public static function parse(array $values): ?array
    {
        $order = [];
        foreach ($values as $value) {
            $type = ResourceType::tryFrom((string) $value);
            if (null !== $type && !\in_array($type, $order, true)) {
                $order[] = $type;
            }
        }

        return \count($order) === \count(ResourceType::cases()) ? $order : null;
    }

    /**
     * Loot from what is available, at most $capacity: evenly, or in the given order.
     *
     * @param list<ResourceType>|null $order
     */
    public static function take(Resources $available, float $capacity, ?array $order): Resources
    {
        $capacity = max(0.0, floor($capacity));
        if (null === $order) {
            $share = $available->total() > 0 ? min(1.0, $capacity / $available->total()) : 0.0;

            return $available->multiply($share)->floor();
        }
        $amounts = [];
        foreach ($order as $type) {
            $amounts[$type->value] = min(max(0.0, floor($available->get($type))), $capacity);
            $capacity -= $amounts[$type->value];
        }

        return Resources::fromArray($amounts);
    }
}
