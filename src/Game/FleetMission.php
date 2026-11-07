<?php

namespace App\Game;

enum FleetMission: string
{
    case Transport = 'transport';
    case Station = 'station';
    case Colonize = 'colonize';
    case Spy = 'spy';
    case Fuse = 'fuse';
    case Supply = 'supply';
    case Trade = 'trade';
    case Attack = 'attack';
    case Salvage = 'salvage';
    /** Wächter and Piraten: attack a visible attacking fleet before it arrives. */
    case Intercept = 'intercept';
    /** Piraten: attack a visible fleet transporting resources. */
    case Raid = 'raid';
    /** Internal: ships flying to join a fleet ("Schiffe zusammenziehen"). */
    case Gather = 'gather';

    /** @return list<self> missions a player can choose when sending ships */
    public static function selectable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $m): bool => !\in_array($m, [self::Gather, self::Intercept, self::Raid], true)));
    }

    public function label(): string
    {
        return match ($this) {
            self::Transport => 'Transport',
            self::Station => 'Stationieren',
            self::Colonize => 'Kolonisieren',
            self::Spy => 'Spionieren',
            self::Fuse => 'Fusionieren',
            self::Supply => 'Beliefern',
            self::Trade => 'Handeln',
            self::Attack => 'Angreifen',
            self::Salvage => 'Bergen',
            self::Intercept => 'Abfangen',
            self::Raid => 'Überfallen',
            self::Gather => 'Zusammenziehen',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Transport => 'Liefert die Ladung an einen besiedelten Planeten ab und kehrt zurück.',
            self::Station => 'Verlegt Schiffe und Ladung dauerhaft auf einen deiner Planeten.',
            self::Colonize => 'Gründet mit einem Kolonieschiff eine Kolonie auf einem freien Platz. Die übrigen Schiffe kehren zurück.',
            self::Spy => 'Späht mit Spähern einen Planeten aus und kehrt mit einem Bericht zurück.',
            self::Fuse => 'Ein Fusionator verschweißt den Zielplaneten mit deinen Planeten darunter und wird dabei verbraucht.',
            self::Gather => 'Schiffe fliegen zu einer Flotte und schließen sich ihr an.',
            self::Supply => 'Bringt Ladung und Fusionatoren der Flotte in das Lager deines Handelsstützpunkts.',
            self::Attack => 'Greift einen fremden Planeten an. Gewinnst du, plünderst du alle ungeschützten Rohstoffe; Bomber töten ungeschützte Einwohner, Invasionsschiffe übernehmen den Planeten.',
            self::Salvage => 'Bergungsschiffe sammeln ein Trümmerfeld ein.',
            self::Intercept => 'Fängt eine sichtbare angreifende Flotte kurz vor ihrem Ziel ab.',
            self::Raid => 'Überfällt eine sichtbare Flotte, die Rohstoffe transportiert. Ohne Bomber bleiben Transportschiffe und Ladung verschont.',
            self::Trade => 'Kauft an einem Handelsstützpunkt die bestellte Ware und bringt sie mit. Gekaufte Fusionatoren schließen sich sofort der Flotte an.',
        };
    }
}
