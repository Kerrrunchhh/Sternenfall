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
    case Colonization = 'colonization';
    case TradeNetwork = 'trade_network';
    case Radar = 'radar';
    case RadarTech = 'radar_tech';

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
            self::Colonization => 'Kolonisationstechnik',
            self::TradeNetwork => 'Handelsnetz',
            self::Radar => 'Radar',
            self::RadarTech => 'Kommunikationstechnik',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::SolidDrive, self::IonDrive, self::PlasmaDrive, self::WarpDrive => 'Antriebe',
            self::ImpulseLaser, self::HeavyLaser, self::ElectronCannon, self::TachyonLauncher, self::PlasmaLauncher => 'Waffen',
            self::RotationShield, self::PhaseShield, self::QuantumShield, self::GravitonShield => 'Schilde',
            self::Espionage, self::InvasionTech, self::FusionTech, self::Colonization, self::TradeNetwork, self::Radar, self::RadarTech => 'Zusatztechnologien',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SolidDrive => 'Einfacher, robuster Raketenantrieb für Frachter. Jede Stufe beschleunigt diese Schiffe um 4 % (Stufe 25 = doppeltes Tempo).',
            self::IonDrive => 'Standardantrieb für Jäger und leichte Kreuzer. Jede Stufe beschleunigt diese Schiffe um 4 % (Stufe 25 = doppeltes Tempo).',
            self::PlasmaDrive => 'Antrieb für Großkampfschiffe und Bomber. Jede Stufe beschleunigt diese Schiffe um 4 % (Stufe 25 = doppeltes Tempo).',
            self::WarpDrive => 'Krümmt den Raum und macht selbst die gewaltigsten Schiffe mobil. Jede Stufe beschleunigt diese Schiffe um 4 % (Stufe 25 = doppeltes Tempo).',
            self::ImpulseLaser => 'Gepulste Laserwaffen für leichte Jäger. Jede Stufe +4 % Angriff für Schiffe und Türme mit dieser Waffe (Stufe 25 = doppelter Angriff).',
            self::HeavyLaser => 'Hochenergielaser mit deutlich größerer Durchschlagskraft. Jede Stufe +4 % Angriff für Schiffe und Türme mit dieser Waffe (Stufe 25 = doppelter Angriff).',
            self::ElectronCannon => 'Beschleunigte Elektronenstrahlen für Kreuzer und Bomber. Jede Stufe +4 % Angriff für Schiffe und Türme mit dieser Waffe (Stufe 25 = doppelter Angriff).',
            self::TachyonLauncher => 'Überlichtschnelle Partikelgeschosse für Großkampfschiffe. Jede Stufe +4 % Angriff für Schiffe und Türme mit dieser Waffe (Stufe 25 = doppelter Angriff).',
            self::PlasmaLauncher => 'Die zerstörerischste Waffe des Universums. Jede Stufe +4 % Angriff für Schiffe und Türme mit dieser Waffe (Stufe 25 = doppelter Angriff).',
            self::RotationShield => 'Rotierende Energiefelder lenken leichte Treffer ab. Jede Stufe +4 % Schild für Schiffe und Türme mit diesem Schild (Stufe 25 = doppelter Schild).',
            self::PhaseShield => 'Phasenverschobene Schilde für schwerere Hüllen. Jede Stufe +4 % Schild für Schiffe und Türme mit diesem Schild (Stufe 25 = doppelter Schild).',
            self::QuantumShield => 'Quantenfelder, ohne die kein Schlachtschiff überlebt. Jede Stufe +4 % Schild für Schiffe und Türme mit diesem Schild (Stufe 25 = doppelter Schild).',
            self::GravitonShield => 'Gravitationsbarrieren für die mächtigsten Schiffe. Jede Stufe +4 % Schild für Schiffe und Türme mit diesem Schild (Stufe 25 = doppelter Schild).',
            self::Espionage => 'Sensorik und Antrieb für Sonden: jede Stufe macht Sonden 4 % schneller. Stufe 1: Planetentyp. Stufe 5: Gebäude, Rohstoffe, Bevölkerung, Forschung. Stufe 10: stationierte Schiffe. Stufe 25: du merkst, wenn du ausspioniert wurdest. Stufe 40: du erfährst auch, wer es war. Stufe 50: du siehst Sonden schon im Anflug auf deine Planeten und Flotten.',
            self::InvasionTech => 'Landungs- und Besiedlungstechnik für Kolonie- und Invasionsschiffe.',
            self::FusionTech => 'Ermöglicht den Fusionator. Die Stufe ist die größte erlaubte Fusion: Stufe 20 verschweißt höchstens 20 Planeten (max. Stufe 49). Langzeitforschung: anfangs schnell, später sehr langsam – die Dauer hängt ganz von den Forschungslaboren ab.',
            self::Colonization => 'Ohne diese Forschung hast du nur deinen Heimatplaneten. Jede Stufe erlaubt 10 Planetenplätze: Stufe 1 = 10, Stufe 2 = 20 usw., ohne Obergrenze (nach rund 10 Jahren sind 500 Plätze möglich); verschweißte Plätze zählen einzeln. Langzeitforschung: jede Stufe dauert sehr lange – die Dauer hängt ganz von den Forschungslaboren ab.',
            self::TradeNetwork => 'Logistik für den Fernhandel. Jede Stufe erlaubt Händlern einen weiteren Handelsstützpunkt.',
            self::Radar => 'Macht angreifende und Rohstoffe transportierende Flotten sichtbar: ab Stufe 1 im eigenen System, je 5 Stufen ein System weiter nach links und rechts.',
            self::RadarTech => 'Hält die Funkverbindung zu startenden Flotten: Nur so lange nach dem Start kannst du sie zurückrufen. Ohne Kommunikationstechnik gar nicht, Stufe 1: 5 Sekunden, Stufe 10: knapp 1 Minute, Stufe 20: 5 Minuten – und weiter steigend.',
        };
    }

    /**
     * Long-term research (fusion and colonization): costs grow gently, the time steeply,
     * and only the research labs decide how long a level takes.
     */
    public function isLongTerm(): bool
    {
        return \in_array($this, [self::FusionTech, self::Colonization], true);
    }

    /** Research has no ceiling and keeps improving; only fusion stops at 49 (no system has more than 50 planets). */
    public const int FUSION_MAX_LEVEL = 49;

    public function maxLevel(): ?int
    {
        return self::FusionTech === $this ? self::FUSION_MAX_LEVEL : null;
    }

    /**
     * Steps of the long-term curve one level stands for: a colonization level gives 10 planet
     * slots and costs and lasts as long as 10 single steps did.
     */
    public function stepsPerLevel(): int
    {
        return self::Colonization === $this ? 10 : 1;
    }

    /** Cost of level 1; every further level doubles (long-term research: grows with level^1.5). */
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
            self::Colonization => new Resources(iron: 2000, titanium: 4000, hydrogen: 1000),
            self::TradeNetwork => new Resources(iron: 2000, titanium: 3000, hydrogen: 500),
            self::Radar => new Resources(iron: 1000, titanium: 2000, hydrogen: 500),
            self::RadarTech => new Resources(iron: 600, titanium: 1200, hydrogen: 300),
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
            self::Colonization => [$lab(1)],
            self::TradeNetwork => [$lab(2), $tech(self::SolidDrive, 1)],
            self::Radar => [$lab(2)],
            self::RadarTech => [$lab(1)],
        };
    }
}
