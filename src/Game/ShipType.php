<?php

namespace App\Game;

/**
 * All ship types, including stationary defense turrets (which stay in the hangar
 * and never join a fleet). In a battle every unit fires once, in firing order.
 */
enum ShipType: string
{
    // Combat ships, in firing order
    case Pfeil = 'pfeil';
    case Falke = 'falke';
    case Lanze = 'lanze';
    case Korsar = 'korsar';
    case Bastion = 'bastion';
    case Mantikor = 'mantikor';
    case Hammerhai = 'hammerhai';
    case Inferno = 'inferno';
    case Monarch = 'monarch';
    case Koloss = 'koloss';
    case Brecher = 'brecher';
    case Leviathan = 'leviathan';
    case HeavyBomber = 'heavy_bomber';
    // Civil and special ships
    case Scout = 'scout';
    case Freighter = 'freighter';
    case LargeFreighter = 'large_freighter';
    case Salvager = 'salvager';
    case ColonyShip = 'colony_ship';
    case InvasionShip = 'invasion_ship';
    case Fusionator = 'fusionator';
    // Planetary defense (stationary)
    case LaserTurret = 'laser_turret';
    case ElectronTurret = 'electron_turret';
    case TachyonTurret = 'tachyon_turret';
    case PlasmaTurret = 'plasma_turret';

    public function label(): string
    {
        return match ($this) {
            self::Pfeil => 'Pfeil',
            self::Falke => 'Falke',
            self::Lanze => 'Lanze',
            self::Korsar => 'Korsar',
            self::Bastion => 'Bastion',
            self::Mantikor => 'Mantikor',
            self::Hammerhai => 'Hammerhai',
            self::Inferno => 'Inferno',
            self::Monarch => 'Monarch',
            self::Koloss => 'Koloss',
            self::Brecher => 'Brecher',
            self::Leviathan => 'Leviathan',
            self::HeavyBomber => 'Schwerer Bomber',
            self::Scout => 'Späher',
            self::Freighter => 'Frachter',
            self::LargeFreighter => 'Großfrachter',
            self::Salvager => 'Bergungsschiff',
            self::ColonyShip => 'Kolonieschiff',
            self::InvasionShip => 'Invasionsschiff',
            self::Fusionator => 'Fusionator',
            self::LaserTurret => 'Lasergeschütz',
            self::ElectronTurret => 'Elektronengeschütz',
            self::TachyonTurret => 'Tachyonengeschütz',
            self::PlasmaTurret => 'Plasmageschütz',
        };
    }

    /** Life form that is exclusively allowed to build this ship (others have to buy it). */
    public function exclusiveBuilder(): ?LifeForm
    {
        return self::Fusionator === $this ? LifeForm::Trader : null;
    }

    public function isCombatShip(): bool
    {
        return !$this->isStationary() && $this->firingOrder() <= self::HeavyBomber->firingOrder();
    }

    /** Defense turrets: built in the shipyard, defend their planet, can never move. */
    public function isStationary(): bool
    {
        return \in_array($this, [self::LaserTurret, self::ElectronTurret, self::TachyonTurret, self::PlasmaTurret], true);
    }

    /** Ships that carry goods; in a raid without bombers they and their cargo are spared. */
    public function isTransport(): bool
    {
        return \in_array($this, [self::Freighter, self::LargeFreighter, self::Salvager], true);
    }

    /**
     * 1-based position in the firing order of a combat round. Turrets fire in
     * between the ships of their generation; civil ships fire last.
     */
    public function firingOrder(): int
    {
        return match ($this) {
            self::Pfeil => 1,
            self::Falke => 2,
            self::LaserTurret => 3,
            self::Lanze => 4,
            self::Korsar => 5,
            self::ElectronTurret => 6,
            self::Bastion => 7,
            self::Mantikor => 8,
            self::Hammerhai => 9,
            self::TachyonTurret => 10,
            self::Inferno => 11,
            self::Monarch => 12,
            self::Koloss => 13,
            self::PlasmaTurret => 14,
            self::Brecher => 15,
            self::Leviathan => 16,
            self::HeavyBomber => 17,
            self::Scout => 18,
            self::Freighter => 19,
            self::LargeFreighter => 20,
            self::Salvager => 21,
            self::ColonyShip => 22,
            self::InvasionShip => 23,
            self::Fusionator => 24,
        };
    }

    /** Weapon research that strengthens this unit's attack (+10 % per level), if any. */
    public function weaponResearch(): ?ResearchType
    {
        foreach ($this->spec()->requirements as $requirement) {
            if ($requirement->subject instanceof ResearchType && 'Waffen' === $requirement->subject->category()) {
                return $requirement->subject;
            }
        }

        return null;
    }

    /** Shield research that strengthens this unit's shield (+10 % per level), if any. */
    public function shieldResearch(): ?ResearchType
    {
        foreach ($this->spec()->requirements as $requirement) {
            if ($requirement->subject instanceof ResearchType && 'Schilde' === $requirement->subject->category()) {
                return $requirement->subject;
            }
        }

        return null;
    }

    public function description(): string
    {
        return match ($this) {
            self::Pfeil => 'Wendiger leichter Jäger. Eröffnet jede Kampfrunde und wird meist in großen Massen gebaut.',
            self::Falke => 'Abfangjäger, feuert direkt nach dem Pfeil. Rückgrat schneller Offensivflotten.',
            self::Lanze => 'Mittelschwerer Jäger mit ausgewogenem Verhältnis aus Feuerkraft und Panzerung.',
            self::Korsar => 'Taktischer Kreuzer der mittleren Generation.',
            self::Bastion => 'Schwerer Kreuzer mit enormen Schilden – fängt feindliche Erstschläge ab.',
            self::Mantikor => 'Offensiv-Spezialist mit hoher Feuerkraft gegen befestigte Stellungen.',
            self::Hammerhai => 'Schlachtschiff mit massiver Panzerung.',
            self::Inferno => 'Zerstörer, der enorme Feuerkraft ins Gefecht bringt.',
            self::Monarch => 'Elite-Schlachtschiff des späten Spiels.',
            self::Koloss => 'Belagerungsschiff. Feuert spät, richtet dann aber verheerenden Schaden an.',
            self::Brecher => 'Spezialisiert darauf, planetare Geschütztürme zu knacken.',
            self::Leviathan => 'Das gewaltigste Kriegsschiff des Universums. Immense Kosten und Bauzeit.',
            self::HeavyBomber => 'Bombardiert Infrastruktur und Bevölkerung. Nur Einwohner in Bunkern sind sicher.',
            self::Scout => 'Extrem schnelle Spionagesonde, die Planeten und Flotten ausspäht.',
            self::Freighter => 'Kleiner Transporter für Rohstoffe zwischen Kolonien.',
            self::LargeFreighter => 'Großer Transporter mit enormer Ladekapazität.',
            self::Salvager => 'Sammelt nach Raumschlachten die Trümmerfelder im Orbit ein.',
            self::ColonyShip => 'Gründet eine Kolonie auf einem freien Planeten und wird dabei verbraucht. Die Besatzung wird zu Siedlern.',
            self::InvasionShip => 'Bringt Truppen für die Übernahme besiedelter feindlicher Planeten.',
            self::LaserTurret => 'Günstiges Abwehrgeschütz gegen leichte Jäger. Steht fest auf dem Planeten.',
            self::ElectronTurret => 'Mittelschweres Abwehrgeschütz. Steht fest auf dem Planeten.',
            self::TachyonTurret => 'Schweres Abwehrgeschütz gegen Großkampfschiffe. Steht fest auf dem Planeten.',
            self::PlasmaTurret => 'Die stärkste planetare Verteidigung. Steht fest auf dem Planeten.',
            self::Fusionator => 'Verschweißt Planeten miteinander. Fliegt zu jedem deiner Planeten und verschweißt ihn mit den eigenen Planeten darunter – wie viele, hängt von der Fusionstechnik ab (max. 50). Nach dem Späher das schnellste Schiff. Nur Händler können ihn bauen; alle anderen kaufen ihn bei Händlern. Eine Fusion kann nur mit genug Wasserstoff wieder gesprengt werden.',
        };
    }

    public function spec(): ShipSpec
    {
        $yard = static fn (int $level): Requirement => new Requirement(BuildingType::Shipyard, $level);
        $tech = static fn (ResearchType $type, int $level): Requirement => new Requirement($type, $level);
        $r = static fn (int $iron, int $titanium, int $hydrogen = 0): Resources => new Resources(iron: $iron, titanium: $titanium, hydrogen: $hydrogen);
        $ion = ResearchType::IonDrive;
        $plasma = ResearchType::PlasmaDrive;
        $warp = ResearchType::WarpDrive;
        $solid = ResearchType::SolidDrive;

        return match ($this) {
            self::Pfeil => new ShipSpec($r(3000, 1000), 2, 50, 10, 400, 12000, 50, 20, $ion,
                [$yard(1), $tech($ion, 1), $tech(ResearchType::ImpulseLaser, 1)]),
            self::Falke => new ShipSpec($r(5000, 2500), 4, 120, 25, 750, 14000, 80, 40, $ion,
                [$yard(2), $tech($ion, 2), $tech(ResearchType::ImpulseLaser, 3), $tech(ResearchType::RotationShield, 1)]),
            self::Lanze => new ShipSpec($r(8000, 5000, 500), 8, 250, 60, 1300, 11000, 100, 75, $ion,
                [$yard(3), $tech($ion, 3), $tech(ResearchType::HeavyLaser, 1), $tech(ResearchType::RotationShield, 2)]),
            self::Korsar => new ShipSpec($r(15000, 10000, 1000), 20, 450, 120, 2500, 10000, 300, 150, $ion,
                [$yard(4), $tech($ion, 4), $tech(ResearchType::HeavyLaser, 3), $tech(ResearchType::PhaseShield, 1)]),
            self::Bastion => new ShipSpec($r(25000, 25000, 2500), 40, 500, 600, 5000, 9000, 500, 250, $plasma,
                [$yard(5), $tech($plasma, 1), $tech(ResearchType::ElectronCannon, 1), $tech(ResearchType::PhaseShield, 3)]),
            self::Mantikor => new ShipSpec($r(30000, 30000, 4000), 50, 1100, 250, 4500, 9500, 400, 300, $plasma,
                [$yard(6), $tech($plasma, 2), $tech(ResearchType::ElectronCannon, 3), $tech(ResearchType::PhaseShield, 3)]),
            self::Hammerhai => new ShipSpec($r(50000, 40000, 8000), 100, 1500, 600, 9000, 8000, 1500, 500, $plasma,
                [$yard(7), $tech($plasma, 4), $tech(ResearchType::ElectronCannon, 5), $tech(ResearchType::QuantumShield, 1)]),
            self::Inferno => new ShipSpec($r(60000, 70000, 12000), 150, 3000, 500, 8000, 8500, 1000, 650, $plasma,
                [$yard(8), $tech($plasma, 5), $tech(ResearchType::TachyonLauncher, 1), $tech(ResearchType::QuantumShield, 2)]),
            self::Monarch => new ShipSpec($r(150000, 120000, 30000), 250, 6000, 2500, 20000, 7000, 3000, 1200, $warp,
                [$yard(10), $tech($warp, 1), $tech(ResearchType::TachyonLauncher, 4), $tech(ResearchType::QuantumShield, 4)]),
            self::Koloss => new ShipSpec($r(250000, 200000, 50000), 350, 12000, 3000, 30000, 3500, 2000, 2000, $warp,
                [$yard(11), $tech($warp, 2), $tech(ResearchType::PlasmaLauncher, 1), $tech(ResearchType::GravitonShield, 1)]),
            self::Brecher => new ShipSpec($r(200000, 250000, 50000), 300, 9000, 4000, 25000, 5000, 1500, 1800, $warp,
                [$yard(11), $tech($warp, 3), $tech(ResearchType::PlasmaLauncher, 2), $tech(ResearchType::QuantumShield, 5)]),
            self::Leviathan => new ShipSpec($r(1000000, 800000, 250000), 500, 40000, 15000, 100000, 4000, 20000, 5000, $warp,
                [$yard(12), $tech($warp, 5), $tech(ResearchType::PlasmaLauncher, 4), $tech(ResearchType::GravitonShield, 3)]),
            self::HeavyBomber => new ShipSpec($r(40000, 35000, 10000), 60, 900, 300, 6000, 5000, 500, 700, $plasma,
                [$yard(6), $tech($plasma, 3), $tech(ResearchType::ElectronCannon, 4), $tech(ResearchType::PhaseShield, 4)]),
            self::Scout => new ShipSpec($r(500, 500, 100), 1, 0, 0, 100, 60000, 5, 1, $solid,
                [$yard(1), $tech($solid, 1), $tech(ResearchType::Espionage, 1)]),
            self::Freighter => new ShipSpec($r(2000, 1000), 3, 5, 10, 400, 6000, 5000, 10, $solid,
                [$yard(1), $tech($solid, 1)]),
            self::LargeFreighter => new ShipSpec($r(6000, 4000), 8, 5, 25, 1200, 8000, 25000, 50, $ion,
                [$yard(4), $tech($ion, 3)]),
            self::Salvager => new ShipSpec($r(8000, 6000, 1000), 10, 1, 10, 1600, 2500, 20000, 300, $ion,
                [$yard(3), $tech($ion, 2), $tech(ResearchType::RotationShield, 2)]),
            self::ColonyShip => new ShipSpec($r(10000, 20000, 10000), 100, 0, 100, 3000, 2500, 7500, 1000, $ion,
                [$yard(4), $tech($ion, 3), $tech(ResearchType::InvasionTech, 1)]),
            self::InvasionShip => new ShipSpec($r(30000, 40000, 15000), 500, 0, 200, 7000, 3000, 1000, 1500, $plasma,
                [$yard(6), $tech($plasma, 2), $tech(ResearchType::InvasionTech, 4)]),
            self::Fusionator => new ShipSpec($r(500000, 1000000, 500000), 1000, 0, 5000, 50000, 40000, 0, 10000, $warp,
                [$yard(10), $tech($warp, 3), $tech(ResearchType::FusionTech, 1)]),
            self::LaserTurret => new ShipSpec($r(1500, 500), 2, 80, 20, 200, 0, 0, 0, $solid,
                [$yard(1), $tech(ResearchType::ImpulseLaser, 1)]),
            self::ElectronTurret => new ShipSpec($r(8000, 6000, 500), 5, 400, 150, 2000, 0, 0, 0, $solid,
                [$yard(4), $tech(ResearchType::ElectronCannon, 1), $tech(ResearchType::RotationShield, 2)]),
            self::TachyonTurret => new ShipSpec($r(40000, 30000, 5000), 15, 2500, 800, 8000, 0, 0, 0, $solid,
                [$yard(7), $tech(ResearchType::TachyonLauncher, 1), $tech(ResearchType::PhaseShield, 3)]),
            self::PlasmaTurret => new ShipSpec($r(120000, 100000, 20000), 40, 8000, 3000, 25000, 0, 0, 0, $solid,
                [$yard(10), $tech(ResearchType::PlasmaLauncher, 1), $tech(ResearchType::QuantumShield, 3)]),
        };
    }
}
