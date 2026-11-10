<?php

namespace App\Game;

/** Classes of warships, from small to large. */
enum ShipClass: string
{
    case Fighter = 'fighter';
    case Frigate = 'frigate';
    case Cruiser = 'cruiser';
    case Battleship = 'battleship';

    public function label(): string
    {
        return match ($this) {
            self::Fighter => 'Jäger',
            self::Frigate => 'Fregatte',
            self::Cruiser => 'Kreuzer',
            self::Battleship => 'Schlachtschiff',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Fighter => 'Jäger',
            self::Frigate => 'Fregatten',
            self::Cruiser => 'Kreuzer',
            self::Battleship => 'Schlachtschiffe',
        };
    }
}
