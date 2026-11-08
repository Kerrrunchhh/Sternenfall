<?php

namespace App\Game;

enum QueueKind: string
{
    case Buildings = 'buildings';
    /** Ships of the shipyard. */
    case Shipyard = 'shipyard';
    /** Defense installations: a queue of their own that runs in parallel to the ships. */
    case Defense = 'defense';

    public static function forShip(ShipType $type): self
    {
        return $type->isStationary() ? self::Defense : self::Shipyard;
    }

    public function label(): string
    {
        return match ($this) {
            self::Buildings => 'Gebäude',
            self::Shipyard => 'Werft',
            self::Defense => 'Verteidigungs',
        };
    }

    /** Longest allowed queue, null for unlimited. */
    public function maxLength(): ?int
    {
        return match ($this) {
            self::Buildings => 2,
            self::Shipyard, self::Defense => null,
        };
    }
}
