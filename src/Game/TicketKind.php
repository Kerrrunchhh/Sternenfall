<?php

namespace App\Game;

/** What a player sends to the admins. */
enum TicketKind: string
{
    case Report = 'report';
    case Suggestion = 'suggestion';
    case Bug = 'bug';

    public function label(): string
    {
        return match ($this) {
            self::Report => 'Spieler melden',
            self::Suggestion => 'Vorschlag',
            self::Bug => 'Fehler',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Report => '🚩',
            self::Suggestion => '💡',
            self::Bug => '🐞',
        };
    }
}
