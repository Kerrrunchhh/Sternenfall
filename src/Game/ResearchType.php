<?php

namespace App\Game;

/**
 * Technologies. Research levels belong to the player and apply to all planets.
 */
enum ResearchType: string
{
    // Drives
    case SolidDrive = 'solid_drive';
    case IonDrive = 'ion_drive';
    case PlasmaDrive = 'plasma_drive';
    case WarpDrive = 'warp_drive';
    // Weapons
    case ImpulseLaser = 'impulse_laser';
    case HeavyLaser = 'heavy_laser';
    case ElectronCannon = 'electron_cannon';
    case TachyonLauncher = 'tachyon_launcher';
    case PlasmaLauncher = 'plasma_launcher';
    // Shields
    case RotationShield = 'rotation_shield';
    case PhaseShield = 'phase_shield';
    case QuantumShield = 'quantum_shield';
    case GravitonShield = 'graviton_shield';
    // Additional technologies
    case Espionage = 'espionage';
    case InvasionTech = 'invasion_tech';
    case FusionTech = 'fusion_tech';
    case TradeNetwork = 'trade_network';
    case Radar = 'radar';

    public function label(): string
    {
        return match ($this) {
            self::SolidDrive => 'Feststoffantrieb',
            self::IonDrive => 'Ionenantrieb',
            self::PlasmaDrive => 'Plasmaantrieb',
            self::WarpDrive => 'Warpantrieb',
            self::ImpulseLaser => 'Impulslaser',
            self::HeavyLaser => 'Schwerer Laser',
            self::ElectronCannon => 'Elektronenkanone',
            self::TachyonLauncher => 'Tachyonenwerfer',
            self::PlasmaLauncher => 'Plasmawerfer',
            self::RotationShield => 'Rotationsschild',
            self::PhaseShield => 'Phasenschild',
            self::QuantumShield => 'Quantenschild',
            self::GravitonShield => 'Gravitonschild',
            self::Espionage => 'Spionagetechnik',
            self::InvasionTech => 'Invasionstechnik',
            self::FusionTech => 'Fusionstechnik',
            self::TradeNetwork => 'Handelsnetz',
            self::Radar => 'Radar',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::SolidDrive, self::IonDrive, self::PlasmaDrive, self::WarpDrive => 'Antriebe',
            self::ImpulseLaser, self::HeavyLaser, self::ElectronCannon, self::TachyonLauncher, self::PlasmaLauncher => 'Waffen',
            self::RotationShield, self::PhaseShield, self::QuantumShield, self::GravitonShield => 'Schilde',
            self::Espionage, self::InvasionTech, self::FusionTech, self::TradeNetwork, self::Radar => 'Zusatztechnologien',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SolidDrive => 'Einfacher, robuster Raketenantrieb für Sonden und Frachter. Jede Stufe beschleunigt diese Schiffe um 10 %.',
            self::IonDrive => 'Standardantrieb für Jäger und leichte Kreuzer. Jede Stufe beschleunigt diese Schiffe um 10 %.',
            self::PlasmaDrive => 'Antrieb für Großkampfschiffe und Bomber. Jede Stufe beschleunigt diese Schiffe um 10 %.',
            self::WarpDrive => 'Krümmt den Raum und macht selbst die gewaltigsten Schiffe mobil. Jede Stufe beschleunigt diese Schiffe um 10 %.',
            self::ImpulseLaser => 'Gepulste Laserwaffen für leichte Jäger.',
            self::HeavyLaser => 'Hochenergielaser mit deutlich größerer Durchschlagskraft.',
            self::ElectronCannon => 'Beschleunigte Elektronenstrahlen für Kreuzer und Bomber.',
            self::TachyonLauncher => 'Überlichtschnelle Partikelgeschosse für Großkampfschiffe.',
            self::PlasmaLauncher => 'Die zerstörerischste Waffe des Universums.',
            self::RotationShield => 'Rotierende Energiefelder lenken leichte Treffer ab.',
            self::PhaseShield => 'Phasenverschobene Schilde für schwerere Hüllen.',
            self::QuantumShield => 'Quantenfelder, ohne die kein Schlachtschiff überlebt.',
            self::GravitonShield => 'Gravitationsbarrieren für die mächtigsten Schiffe.',
            self::Espionage => 'Sensorik und Tarntechnik für Späher.',
            self::InvasionTech => 'Landungs- und Besiedlungstechnik für Kolonie- und Invasionsschiffe.',
            self::FusionTech => 'Ermöglicht den Fusionator. Jede Stufe erlaubt es, einen weiteren Planeten zu verschweißen (max. 50).',
            self::TradeNetwork => 'Logistik für den Fernhandel. Jede Stufe erlaubt Händlern einen weiteren Handelsstützpunkt.',
            self::Radar => 'Macht angreifende und Rohstoffe transportierende Flotten sichtbar: ab Stufe 1 im eigenen System, je 5 Stufen ein System weiter nach links und rechts.',
        };
    }

    /** Cost of level 1; every further level doubles. */
    public function baseCost(): Resources
    {
        return match ($this) {
            self::SolidDrive => new Resources(iron: 400, titanium: 600),
            self::IonDrive => new Resources(iron: 2000, titanium: 4000, hydrogen: 600),
            self::PlasmaDrive => new Resources(iron: 10000, titanium: 20000, hydrogen: 6000),
            self::WarpDrive => new Resources(iron: 40000, titanium: 80000, hydrogen: 25000),
            self::ImpulseLaser => new Resources(iron: 300, titanium: 700),
            self::HeavyLaser => new Resources(iron: 1500, titanium: 3000, hydrogen: 300),
            self::ElectronCannon => new Resources(iron: 6000, titanium: 12000, hydrogen: 2000),
            self::TachyonLauncher => new Resources(iron: 25000, titanium: 50000, hydrogen: 10000),
            self::PlasmaLauncher => new Resources(iron: 80000, titanium: 160000, hydrogen: 40000),
            self::RotationShield => new Resources(iron: 800, titanium: 1200),
            self::PhaseShield => new Resources(iron: 4000, titanium: 8000, hydrogen: 1000),
            self::QuantumShield => new Resources(iron: 15000, titanium: 30000, hydrogen: 6000),
            self::GravitonShield => new Resources(iron: 60000, titanium: 120000, hydrogen: 30000),
            self::Espionage => new Resources(iron: 200, titanium: 1000, hydrogen: 200),
            self::InvasionTech => new Resources(iron: 4000, titanium: 8000, hydrogen: 4000),
            self::FusionTech => new Resources(iron: 200000, titanium: 400000, hydrogen: 200000),
            self::TradeNetwork => new Resources(iron: 2000, titanium: 3000, hydrogen: 500),
            self::Radar => new Resources(iron: 1000, titanium: 2000, hydrogen: 500),
        };
    }

    /** @return list<Requirement> */
    public function requirements(): array
    {
        $lab = static fn (int $level): Requirement => new Requirement(BuildingType::ResearchLab, $level);
        $tech = static fn (self $type, int $level): Requirement => new Requirement($type, $level);

        return match ($this) {
            self::SolidDrive, self::ImpulseLaser, self::Espionage => [$lab(1)],
            self::IonDrive => [$lab(2), $tech(self::SolidDrive, 2)],
            self::PlasmaDrive => [$lab(5), $tech(self::IonDrive, 4)],
            self::WarpDrive => [$lab(8), $tech(self::PlasmaDrive, 5)],
            self::HeavyLaser => [$lab(2), $tech(self::ImpulseLaser, 3)],
            self::ElectronCannon => [$lab(4), $tech(self::HeavyLaser, 3)],
            self::TachyonLauncher => [$lab(7), $tech(self::ElectronCannon, 4)],
            self::PlasmaLauncher => [$lab(9), $tech(self::TachyonLauncher, 4)],
            self::RotationShield => [$lab(2)],
            self::PhaseShield => [$lab(4), $tech(self::RotationShield, 3)],
            self::QuantumShield => [$lab(6), $tech(self::PhaseShield, 4)],
            self::GravitonShield => [$lab(9), $tech(self::QuantumShield, 4)],
            self::InvasionTech => [$lab(3), $tech(self::IonDrive, 2)],
            self::FusionTech => [$lab(10), $tech(self::WarpDrive, 2), $tech(self::GravitonShield, 1)],
            self::TradeNetwork => [$lab(2), $tech(self::SolidDrive, 1)],
            self::Radar => [$lab(2)],
        };
    }
}
