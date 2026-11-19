<?php

namespace App\Game;

/** Folders of the system messages ("Nachrichten"); everything that is no battle, espionage, trade or transport is general. */
enum MessageFolder: string
{
    case Battle = 'battle';
    case Spy = 'spy';
    case Trade = 'trade';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Battle => 'Kampfberichte',
            self::Spy => 'Spionage',
            self::Trade => 'Handel & Transport',
            self::General => 'Allgemein',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Battle => '⚔️',
            self::Spy => '🔭',
            self::Trade => '📦',
            self::General => '📋',
        };
    }
}
