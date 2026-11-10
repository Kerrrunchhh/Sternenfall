<?php

namespace App\Game;

enum TicketStatus: string
{
    case Open = 'open';
    case Question = 'question';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Forwarded = 'forwarded';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Offen',
            self::Question => 'Rückfrage',
            self::Accepted => 'Angenommen',
            self::Rejected => 'Abgelehnt',
            self::Forwarded => 'An den Entwickler weitergeleitet',
        };
    }

    /** Still something to do for the admins. */
    public function isPending(): bool
    {
        return self::Open === $this || self::Question === $this;
    }
}
