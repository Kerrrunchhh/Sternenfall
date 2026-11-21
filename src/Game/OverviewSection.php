<?php

namespace App\Game;

/**
 * The boxes on the overview a player may hide and reorder (Konto). The attack alarm always shows.
 * The order of the cases is the default order.
 */
enum OverviewSection: string
{
    case Contacts = 'contacts';
    case Board = 'board';
    case Movements = 'movements';
    case Queues = 'queues';
    case TransportRequests = 'transport_requests';
    case Research = 'research';
    case Ticker = 'ticker';
    case Resources = 'resources';
    case Population = 'population';
    case Planet = 'planet';
    case Hangar = 'hangar';

    public function label(): string
    {
        return match ($this) {
            self::Board => 'Schwarzes Brett',
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
