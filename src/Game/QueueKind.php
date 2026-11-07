<?php

namespace App\Game;

enum QueueKind: string
{
    case Buildings = 'buildings';
    case Shipyard = 'shipyard';

    public function label(): string
    {
        return match ($this) {
            self::Buildings => 'Gebäude',
            self::Shipyard => 'Werft',
        };
    }

    public function maxLength(): int
    {
        return match ($this) {
            self::Buildings => 10,
            self::Shipyard => 20,
        };
    }
}
