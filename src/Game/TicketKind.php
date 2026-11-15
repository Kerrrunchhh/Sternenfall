<?php

namespace App\Game;

/** What a player sends to the admins – or, for a multi check, what the team asks a player. */
enum TicketKind: string
{
    case Report = 'report';
    case Suggestion = 'suggestion';
    case Bug = 'bug';
    /** Started by a moderator: the player is asked about a possible multi account and may explain. */
    case MultiCheck = 'multi_check';

    /** @return list<self> what players may send themselves */
    public static function forPlayers(): array
    {
        return [self::Report, self::Suggestion, self::Bug];
    }

    /** The team wrote first and stays anonymous towards the player. */
    public function isFromTeam(): bool
    {
        return self::MultiCheck === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Report => 'Spieler melden',
            self::Suggestion => 'Vorschlag',
            self::Bug => 'Fehler',
            self::MultiCheck => 'Multi-Verdacht',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Report => '🚩',
            self::Suggestion => '💡',
            self::Bug => '🐞',
            self::MultiCheck => '👥',
        };
    }
}
