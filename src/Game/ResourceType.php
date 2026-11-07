<?php

namespace App\Game;

enum ResourceType: string
{
    case Iron = 'iron';
    case Titanium = 'titanium';
    case Food = 'food';
    case Water = 'water';
    case Hydrogen = 'hydrogen';

    public function label(): string
    {
        return match ($this) {
            self::Iron => 'Eisen',
            self::Titanium => 'Titan',
            self::Food => 'Nahrung',
            self::Water => 'Wasser',
            self::Hydrogen => 'Wasserstoff',
        };
    }

    /** Production per hour every planet has without any buildings. */
    public function baseProduction(): float
    {
        return match ($this) {
            self::Iron => 20,
            self::Titanium, self::Food, self::Water => 10,
            self::Hydrogen => 0,
        };
    }
}
