<?php

namespace App\Game;

/**
 * The kind of world a planet slot is. Fixed per slot (see Universe::planetType),
 * so the galaxy shows it even before a slot is colonized.
 */
enum PlanetType: string
{
    case Blue = 'blue';
    case Red = 'red';
    case Water = 'water';
    case Stone = 'stone';
    case Desert = 'desert';

    public function label(): string
    {
        return match ($this) {
            self::Blue => 'Blauer Planet',
            self::Red => 'Roter Planet',
            self::Water => 'Wasserplanet',
            self::Stone => 'Steinplanet',
            self::Desert => 'Wüstenplanet',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Blue => '🔵',
            self::Red => '🔴',
            self::Water => '🌊',
            self::Stone => '🪨',
            self::Desert => '🏜️',
        };
    }

    public function effect(): string
    {
        return match ($this) {
            self::Blue => 'Bevölkerung wächst doppelt so schnell',
            self::Red => 'Nahrungsproduktion +50 %',
            self::Water => 'Wasserproduktion +50 %',
            self::Stone => 'Nahrungsproduktion −50 %',
            self::Desert => 'Nahrungs- und Wasserproduktion −50 %',
        };
    }

    public function growthFactor(): float
    {
        return self::Blue === $this ? 2.0 : 1.0;
    }

    public function productionFactor(ResourceType $resource): float
    {
        return match ([$this, $resource]) {
            [self::Red, ResourceType::Food], [self::Water, ResourceType::Water] => 1.5,
            [self::Stone, ResourceType::Food], [self::Desert, ResourceType::Food], [self::Desert, ResourceType::Water] => 0.5,
            default => 1.0,
        };
    }

    /** Types a new player may start on (no hostile worlds). */
    public function isFriendly(): bool
    {
        return \in_array($this, [self::Blue, self::Red, self::Water], true);
    }
}
