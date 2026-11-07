<?php

namespace App\Game;

/**
 * What a buyer actually gets at a trade post: as much of the order as stock,
 * credits and free cargo space allow. Fusionators are bought first (they need
 * no cargo space), then the resources in their usual order.
 *
 * Goods are keyed like trade post stock: resource type values plus "fusionator".
 */
final readonly class TradeDeal
{
    public const string FUSIONATOR = 'fusionator';

    /**
     * @param array<string, float> $bought goods => amount
     */
    public function __construct(
        public array $bought,
        public float $price,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0.0 === (float) array_sum($this->bought);
    }

    /** @return list<string> all tradeable goods */
    public static function goods(): array
    {
        return [self::FUSIONATOR, ...array_map(static fn (ResourceType $t): string => $t->value, ResourceType::cases())];
    }

    public static function label(string $good): string
    {
        return self::FUSIONATOR === $good ? 'Fusionator' : ResourceType::from($good)->label();
    }

    /**
     * @param array<string, float> $order  goods => wanted amount
     * @param array<string, float> $stock  goods => available amount
     * @param array<string, float> $prices goods => credits per unit (missing or 0: not for sale)
     * @param bool                 $free   the owner picks up his own goods without paying
     */
    public static function compute(array $order, array $stock, array $prices, float $credits, float $freeCargo, bool $free = false): self
    {
        $bought = [];
        $total = 0.0;
        foreach (self::goods() as $good) {
            $wanted = max(0, floor($order[$good] ?? 0));
            $price = $free ? 0.0 : (float) ($prices[$good] ?? 0);
            if (0.0 === $wanted || (!$free && $price <= 0)) {
                continue;
            }

            $amount = min($wanted, floor($stock[$good] ?? 0));
            if (self::FUSIONATOR !== $good) {
                $amount = min($amount, floor($freeCargo));
            }
            if ($price > 0) {
                $amount = min($amount, floor(($credits - $total) / $price));
            }
            if ($amount <= 0) {
                continue;
            }

            $bought[$good] = $amount;
            $total += $amount * $price;
            if (self::FUSIONATOR !== $good) {
                $freeCargo -= $amount;
            }
        }

        return new self($bought, $total);
    }
}
