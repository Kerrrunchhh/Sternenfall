<?php

namespace App\Game;

/**
 * Immutable bundle of amounts for every resource type.
 */
final readonly class Resources
{
    public function __construct(
        public float $iron = 0,
        public float $titanium = 0,
        public float $food = 0,
        public float $water = 0,
        public float $hydrogen = 0,
    ) {
    }

    /** @param callable(ResourceType): float $amount */
    public static function fromCallback(callable $amount): self
    {
        $values = [];
        foreach (ResourceType::cases() as $type) {
            $values[$type->value] = $amount($type);
        }

        return new self(...$values);
    }

    public function get(ResourceType $type): float
    {
        return $this->{$type->value};
    }

    public function add(self $other): self
    {
        return self::fromCallback(fn (ResourceType $t) => $this->get($t) + $other->get($t));
    }

    public function subtract(self $other): self
    {
        return self::fromCallback(fn (ResourceType $t) => $this->get($t) - $other->get($t));
    }

    public function multiply(float $factor): self
    {
        return self::fromCallback(fn (ResourceType $t) => $this->get($t) * $factor);
    }

    public function floor(): self
    {
        return self::fromCallback(fn (ResourceType $t) => floor($this->get($t)));
    }

    public function ceil(): self
    {
        return self::fromCallback(fn (ResourceType $t) => ceil($this->get($t)));
    }

    public function total(): float
    {
        return array_sum($this->toArray());
    }

    public function isEmpty(): bool
    {
        return 0.0 === (float) $this->total();
    }

    /** @return array<string, float> */
    public function toArray(): array
    {
        $values = [];
        foreach (ResourceType::cases() as $type) {
            $values[$type->value] = $this->get($type);
        }

        return $values;
    }

    /** @param array<string, float|int> $values */
    public static function fromArray(array $values): self
    {
        return self::fromCallback(static fn (ResourceType $t): float => (float) ($values[$t->value] ?? 0));
    }

    public function covers(self $cost): bool
    {
        foreach (ResourceType::cases() as $type) {
            if ($this->get($type) < $cost->get($type)) {
                return false;
            }
        }

        return true;
    }
}
