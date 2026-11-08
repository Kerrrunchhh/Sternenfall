<?php

namespace App\Game;

/**
 * All buildings that can be constructed on a planet, with their static balancing data.
 */
enum BuildingType: string
{
    case CommandCenter = 'command_center';
    case IronMine = 'iron_mine';
    case TitaniumMine = 'titanium_mine';
    case WaterPump = 'water_pump';
    case Farm = 'farm';
    case Electrolysis = 'electrolysis';
    case Storage = 'storage';
    case BioCell = 'bio_cell';
    case Bunker = 'bunker';
    case ResearchLab = 'research_lab';
    case Shipyard = 'shipyard';
    case ShieldGenerator = 'shield_generator';

    public function label(): string
    {
        return match ($this) {
            self::CommandCenter => 'Kommandozentrale',
            self::IronMine => 'Eisenmine',
            self::TitaniumMine => 'Titanmine',
            self::WaterPump => 'Wasserpumpwerk',
            self::Farm => 'Hydrokulturfarm',
            self::Electrolysis => 'Elektrolyseanlage',
            self::Storage => 'Lagerkomplex',
            self::BioCell => 'Biozelle',
            self::Bunker => 'Bunker',
            self::ResearchLab => 'Forschungslabor',
            self::Shipyard => 'Raumwerft',
            self::ShieldGenerator => 'Schildgenerator',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CommandCenter => 'Koordiniert alle Bauvorhaben. Jede Stufe verkürzt die Bauzeit aller Gebäude.',
            self::IronMine => 'Baut Eisenerz ab – der wichtigste Baustoff jeder Kolonie.',
            self::TitaniumMine => 'Gewinnt Titan, das leichte und harte Metall für Schiffsrümpfe und Hightech.',
            self::WaterPump => 'Pumpt Wasser aus unterirdischen Eisvorkommen.',
            self::Farm => 'Erzeugt Nahrung in klimatisierten Hydrokulturen.',
            self::Electrolysis => 'Spaltet Wasser in Wasserstoff, den Treibstoff deiner Flotten.',
            self::BioCell => 'Lebensraum für deine Bevölkerung. Jede Stufe erhöht die maximale Einwohnerzahl.',
            self::Bunker => 'Unterirdische Schutzräume, die deine Bevölkerung vor Bombardements schützen.',
            self::Storage => 'Gesicherte Lagerhallen: Rohstoffe bis zu dieser Menge sind vor Plünderung durch Angreifer geschützt.',
            self::ResearchLab => 'Erforscht neue Technologien. Jede Stufe verkürzt die Forschungszeit und schaltet weitere Technologien frei.',
            self::Shipyard => 'Baut Raumschiffe. Jede Stufe verkürzt die Bauzeit und schaltet größere Schiffsklassen frei.',
            self::ShieldGenerator => 'Spannt einen Schutzschild um den Planeten (20.000 je Stufe, bei Fusionen werden die Stufen addiert). Ist die Angriffskraft der Angreifer, die den Kampf überstehen, größer als der Schild, wird der unterste Planet einer Fusion abgesprengt.',
        };
    }

    /** Cost of level 1. */
    public function baseCost(): Resources
    {
        return match ($this) {
            self::CommandCenter => new Resources(iron: 200, titanium: 120, hydrogen: 50),
            self::IronMine => new Resources(iron: 60, titanium: 15),
            self::TitaniumMine => new Resources(iron: 48, titanium: 24),
            self::WaterPump => new Resources(iron: 40, titanium: 10),
            self::Farm => new Resources(iron: 50, titanium: 20, water: 30),
            self::Electrolysis => new Resources(iron: 180, titanium: 90, water: 60),
            self::Storage => new Resources(iron: 400, titanium: 200),
            self::BioCell => new Resources(iron: 150, titanium: 80, water: 50),
            self::Bunker => new Resources(iron: 300, titanium: 150),
            self::ResearchLab => new Resources(iron: 200, titanium: 400, hydrogen: 200),
            self::Shipyard => new Resources(iron: 400, titanium: 200, hydrogen: 100),
            self::ShieldGenerator => new Resources(iron: 800, titanium: 600, hydrogen: 300),
        };
    }

    /** Multiplier applied to the cost for each additional level. */
    public function costFactor(): float
    {
        return match ($this) {
            self::IronMine, self::WaterPump, self::Farm => 1.5,
            self::Electrolysis => 1.55,
            self::TitaniumMine => 1.6,
            self::Storage => 1.8,
            self::BioCell, self::Bunker, self::ShieldGenerator => 1.7,
            self::CommandCenter, self::ResearchLab, self::Shipyard => 2.0,
        };
    }

    /** The resource this building produces, if any. */
    public function produces(): ?ResourceType
    {
        return match ($this) {
            self::IronMine => ResourceType::Iron,
            self::TitaniumMine => ResourceType::Titanium,
            self::WaterPump => ResourceType::Water,
            self::Farm => ResourceType::Food,
            self::Electrolysis => ResourceType::Hydrogen,
            default => null,
        };
    }

    /** Production per hour at level 1 (before level scaling). */
    public function productionBase(): float
    {
        return match ($this) {
            self::IronMine => 25,
            self::WaterPump => 20,
            self::Farm => 18,
            self::TitaniumMine => 14,
            self::Electrolysis => 8,
            default => 0,
        };
    }

    /** @return list<Requirement> */
    public function requirements(): array
    {
        return match ($this) {
            self::Electrolysis, self::ResearchLab, self::Bunker => [new Requirement(self::CommandCenter, 2)],
            self::Shipyard, self::ShieldGenerator => [new Requirement(self::CommandCenter, 3)],
            default => [],
        };
    }
}
