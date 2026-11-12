<?php

namespace App\Game;

enum FleetMission: string
{
    case Transport = 'transport';
    /** Delivers the cargo and stays at the planet instead of flying back. */
    case TransportStation = 'transport_station';
    case Station = 'station';
    /** Named fleet flies to any settled planet and waits there; unseen unless it carries cargo. */
    case Jump = 'jump';
    case Colonize = 'colonize';
    case Spy = 'spy';
    case Fuse = 'fuse';
    case Supply = 'supply';
    case Pickup = 'pickup';
    case Trade = 'trade';
    case Attack = 'attack';
    case Salvage = 'salvage';
    /** Wächter and Piraten: attack a visible attacking fleet before it arrives. */
    case Intercept = 'intercept';
    /** Piraten: attack a visible fleet transporting resources. */
    case Raid = 'raid';
    /** Hovering in place, invisible; duration given by a fusionator elsewhere. */
    case Hover = 'hover';
    /** Internal: ships flying to join a fleet ("Schiffe zusammenziehen"). */
    case Gather = 'gather';

    /** @return list<self> missions a player can choose when sending ships */
    public static function selectable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $m): bool => !\in_array($m, [self::Gather, self::Intercept, self::Raid, self::Hover, self::Spy], true)));
    }

    /**
     * Missions after which the fleet stays at the target (or burns up): only the way there costs
     * fuel and provisions. Stationing unnamed ships puts them into the hangar, so it stays too;
     * "transport and station" only stays with a named fleet.
     */
    public function isOneWay(bool $named): bool
    {
        return match ($this) {
            self::Spy, self::Station, self::Jump, self::Trade => true,
            self::TransportStation => $named,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Transport => 'Transport',
            self::TransportStation => 'Transportieren und stationieren',
            self::Station => 'Stationieren',
            self::Jump => 'Springen',
            self::Colonize => 'Kolonisieren',
            self::Spy => 'Spionieren',
            self::Fuse => 'Fusionieren',
            self::Supply => 'Beliefern',
            self::Pickup => 'Rohstoffe abholen',
            self::Trade => 'Handeln',
            self::Attack => 'Angreifen',
            self::Salvage => 'Bergen',
            self::Intercept => 'Abfangen',
            self::Raid => 'Überfallen',
            self::Hover => 'Schweben',
            self::Gather => 'Zusammenziehen',
        };
    }

    /** The mission as a noun, for a fleet underway ("Angriff von … nach …"); label() is the order. */
    public function flightLabel(): string
    {
        return match ($this) {
            self::Transport => 'Transport',
            self::TransportStation => 'Transport mit Stationierung',
            self::Station => 'Stationierung',
            self::Jump => 'Sprung',
            self::Colonize => 'Kolonisierung',
            self::Spy => 'Spionage',
            self::Fuse => 'Fusion',
            self::Supply => 'Belieferung',
            self::Pickup => 'Abholung',
            self::Trade => 'Handelsflug',
            self::Attack => 'Angriff',
            self::Salvage => 'Bergung',
            self::Intercept => 'Abfangmanöver',
            self::Raid => 'Überfall',
            self::Hover => 'Schwebeflug',
            self::Gather => 'Sammelflug',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Transport => 'Liefert die Ladung an einen besiedelten Planeten ab und kehrt zurück.',
            self::TransportStation => 'Liefert die Ladung an einen besiedelten Planeten ab und bleibt dort stationiert, statt zurückzufliegen. Mit Ladung ist die Flotte unterwegs sichtbar.',
            self::Station => 'Verlegt Schiffe und Ladung dauerhaft auf einen deiner Planeten.',
            self::Jump => 'Springt mit normaler Flugzeit zu einem besiedelten Planeten (auch fremd) und wartet dort auf Befehle. Ohne Fracht unsichtbar und unangreifbar; mit Fracht sichtbar und von Piraten abfangbar.',
            self::Colonize => 'Gründet mit einem Kolonieschiff eine Kolonie auf einem freien Platz. Schneller geht es mit „Kolonisieren“ im Universum: dann startet ein Kolonieschiff direkt aus dem Hangar des gerade gewählten Planeten. Übrige Schiffe einer Flotte kehren zurück.',
            self::Spy => 'Eine Sonde fliegt direkt aus dem Hangar (🔭 in Universum oder Radar), schickt einen Bericht und verglüht danach. Wie viel du erfährst, hängt von deiner Spionagetechnik ab.',
            self::Fuse => 'Schicke den Fusionator zu dem Planeten, der angeschweißt werden soll: Er verschweißt ihn mit deinem Planeten direkt darüber und wird dabei verbraucht.',
            self::Gather => 'Schiffe fliegen zu einer Flotte und schließen sich ihr an.',
            self::Supply => 'Bringt die Ladung der Flotte in das Lager deines Handelsstützpunkts.',
            self::Pickup => 'Handelsschiffe fliegen zu einem deiner Planeten, laden dort die angegebene Menge Rohstoffe ein (je Flotte, höchstens was im Lager liegt und in den Frachtraum passt) und bringen sie ins Lager des Planeten, von dem sie gestartet sind. Mitgebrachte Ladung liefern sie vorher dort ab.',
            self::Attack => 'Greift einen fremden Planeten an. Frachter fliegen nicht mit – die Beute tragen Bergungsschiffe. Gewinnst du, plünderst du die ungeschützten Rohstoffe, so weit die Bergungsschiffe sie fassen; Bomber töten ungeschützte Einwohner, Invasionsschiffe übernehmen den Planeten mit einer Chance je nach Einwohnerzahl. Durchbricht die Flotte den Schild einer Fusion, wird deren unterster Planet abgesprengt.',
            self::Salvage => 'Bergungsschiffe sammeln ein Trümmerfeld ein.',
            self::Intercept => 'Fängt eine sichtbare angreifende Flotte kurz vor ihrem Ziel ab.',
            self::Hover => 'Die Flotte schwebt unsichtbar über ihrem Standort und landet danach wieder dort.',
            self::Raid => 'Überfällt eine sichtbare Flotte, die Rohstoffe transportiert. Ohne Bomber bleiben Transportschiffe und Ladung verschont.',
            self::Trade => 'Fliegt zu einem Handelsstützpunkt oder einer Zentralbörse der Gilde und wartet dort (nur Frachter, benannte Flotte). Kaufen und verkaufen kannst du dann auf der Seite des Stützpunkts; mitgebrachte Rohstoffe verkaufst du dort.',
        };
    }
}
