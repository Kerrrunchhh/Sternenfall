<?php

namespace App\Game;

/**
 * What a unit is as a target, and whom a unit shoots first. A unit spends its damage on the
 * first group in its priorities that still has living enemies; what is left once they are all
 * destroyed goes on to the next group. Within a group the damage is spread by hit points.
 *
 * Fighters hunt invasion ships and bombers; every class fights the others before its own.
 */
enum TargetGroup: string
{
    /** Invasion ships and heavy bombers: the threat to a planet. */
    case Raiders = 'raiders';
    case Fighters = 'fighters';
    case Frigates = 'frigates';
    case Cruisers = 'cruisers';
    case Battleships = 'battleships';
    case Turrets = 'turrets';
    /** Probes, freighters, salvagers, colony ships, fusionators. */
    case Civil = 'civil';

    public static function of(ShipType $type): self
    {
        if (ShipType::InvasionShip === $type || ShipType::HeavyBomber === $type) {
            return self::Raiders;
        }
        if ($type->isStationary()) {
            return self::Turrets;
        }

        return match ($type->shipClass()) {
            ShipClass::Fighter => self::Fighters,
            ShipClass::Frigate => self::Frigates,
            ShipClass::Cruiser => self::Cruisers,
            ShipClass::Battleship => self::Battleships,
            default => self::Civil,
        };
    }

    /** @return list<self> whom the unit shoots, first things first */
    public static function prioritiesOf(ShipType $shooter): array
    {
        if (ShipType::Brecher === $shooter) {
            return [self::Turrets, self::Cruisers, self::Frigates, self::Fighters, self::Raiders, self::Civil, self::Battleships];
        }
        if ($shooter->isStationary()) {
            return [self::Raiders, self::Battleships, self::Cruisers, self::Frigates, self::Fighters, self::Civil];
        }

        // Bombers shoot like the cruisers they are.
        return match ($shooter->shipClass()) {
            ShipClass::Fighter => [self::Raiders, self::Civil, self::Frigates, self::Cruisers, self::Battleships, self::Turrets, self::Fighters],
            ShipClass::Frigate => [self::Fighters, self::Raiders, self::Cruisers, self::Battleships, self::Civil, self::Turrets, self::Frigates],
            ShipClass::Cruiser => [self::Frigates, self::Fighters, self::Battleships, self::Raiders, self::Civil, self::Turrets, self::Cruisers],
            ShipClass::Battleship => [self::Cruisers, self::Frigates, self::Fighters, self::Raiders, self::Civil, self::Turrets, self::Battleships],
            default => [self::Fighters, self::Frigates, self::Cruisers, self::Battleships, self::Raiders, self::Civil, self::Turrets],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Raiders => 'Invasionsschiffe und Bomber',
            self::Fighters => 'Jäger',
            self::Frigates => 'Fregatten',
            self::Cruisers => 'Kreuzer',
            self::Battleships => 'Schlachtschiffe',
            self::Turrets => 'Türme',
            self::Civil => 'zivile Schiffe',
        };
    }
}
