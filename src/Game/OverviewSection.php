<?php

namespace App\Game;

/** The boxes on the overview a player may hide (Konto). The attack alarm always shows. */
enum OverviewSection: string
{
    case Contacts = 'contacts';
    case Movements = 'movements';
    case TransportRequests = 'transport_requests';
    case Research = 'research';
    case Ticker = 'ticker';
    case Queues = 'queues';
    case Resources = 'resources';
    case Population = 'population';
    case Planet = 'planet';
    case Hangar = 'hangar';

    public function label(): string
    {
        return match ($this) {
            self::Contacts => 'Schnellkontakte',
            self::Movements => 'Flottenbewegungen',
            self::TransportRequests => 'Transportgesuche',
            self::Research => 'Forschung',
            self::Ticker => 'Ticker',
            self::Queues => 'Bauschleifen',
            self::Resources => 'Rohstoffe pro Stunde',
            self::Population => 'Bevölkerung',
            self::Planet => 'Planet',
            self::Hangar => 'Hangar',
        };
    }
}
