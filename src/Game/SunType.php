<?php

namespace App\Game;

/** The star in the middle of a solar system – only for the looks, fixed per system. */
enum SunType: string
{
    case YellowDwarf = 'yellow_dwarf';
    case RedDwarf = 'red_dwarf';
    case OrangeDwarf = 'orange_dwarf';
    case WhiteDwarf = 'white_dwarf';
    case BlueGiant = 'blue_giant';
    case RedGiant = 'red_giant';
    case BinaryStar = 'binary_star';

    public static function of(int $galaxy, int $system): self
    {
        $types = self::cases();

        return $types[crc32("sun:$galaxy:$system") % \count($types)];
    }

    public function label(): string
    {
        return match ($this) {
            self::YellowDwarf => 'Gelber Zwerg',
            self::RedDwarf => 'Roter Zwerg',
            self::OrangeDwarf => 'Oranger Zwerg',
            self::WhiteDwarf => 'Weißer Zwerg',
            self::BlueGiant => 'Blauer Riese',
            self::RedGiant => 'Roter Riese',
            self::BinaryStar => 'Doppelstern',
        };
    }

    /** Colour of the star in the system view. */
    public function color(): string
    {
        return match ($this) {
            self::YellowDwarf => '#ffd54f',
            self::RedDwarf => '#ff7043',
            self::OrangeDwarf => '#ffa726',
            self::WhiteDwarf => '#e3f2fd',
            self::BlueGiant => '#64b5f6',
            self::RedGiant => '#e53935',
            self::BinaryStar => '#fff59d',
        };
    }

    /** Size of the star in the system view (relative). */
    public function size(): float
    {
        return match ($this) {
            self::RedDwarf, self::WhiteDwarf => 0.7,
            self::YellowDwarf, self::OrangeDwarf, self::BinaryStar => 1.0,
            self::BlueGiant, self::RedGiant => 1.5,
        };
    }
}
