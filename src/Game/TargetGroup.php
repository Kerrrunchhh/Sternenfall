<?php

namespace App\Game;

/**
 * What a unit is as a target, and whom a unit shoots first. A unit spends its damage on the
 * first group in its priorities that still has living enemies; what is left once they are all
 * destroyed goes on to the next group. Within a group the damage is spread by hit points.
 *
 * Fighters hunt invasion ships and bombers (with triple damage); every class fights the others
 * before its own; breakers go for the turrets (triple damage too).
 */
enum TargetGroup: string
{
    /** Invasion ships and heavy bombers are the threat to a planet; invasion ships come first. */
    case Invaders = 'invaders';
    case Bombers = 'bombers';
    case Fighters = 'fighters';
    case Frigates = 'frigates';
    case Cruisers = 'cruisers';
    case Battleships = 'battleships';
    case Turrets = 'turrets';
    /** Probes, freighters, salvagers, colony ships, fusionators. */
    case Civil = 'civil';

    public static function of(ShipType $type): self
    {
        if (ShipType::InvasionShip === $type) {
            return self::Invaders;
        }
        if (ShipType::HeavyBomber === $type) {
            return self::Bombers;
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
            return [self::Turrets, self::Cruisers, self::Frigates, self::Fighters, self::Invaders, self::Bombers, self::Civil, self::Battleships];
        }
        if ($shooter->isStationary()) {
            return [self::Invaders, self::Bombers, self::Battleships, self::Cruisers, self::Frigates, self::Fighters, self::Civil];
        }

        // Bombers shoot like the cruisers they are.
        return match ($shooter->shipClass()) {
            ShipClass::Fighter => [self::Invaders, self::Bombers, self::Civil, self::Frigates, self::Cruisers, self::Battleships, self::Turrets, self::Fighters],
            ShipClass::Frigate => [self::Fighters, self::Invaders, self::Bombers, self::Cruisers, self::Battleships, self::Civil, self::Turrets, self::Frigates],
            ShipClass::Cruiser => [self::Frigates, self::Fighters, self::Battleships, self::Invaders, self::Bombers, self::Civil, self::Turrets, self::Cruisers],
            ShipClass::Battleship => [self::Cruisers, self::Frigates, self::Fighters, self::Invaders, self::Bombers, self::Civil, self::Turrets, self::Battleships],
            default => [self::Fighters, self::Frigates, self::Cruisers, self::Battleships, self::Invaders, self::Bombers, self::Civil, self::Turrets],
        };
    }

    /** Breakers hit turrets, fighters invasion ships and bombers three times as hard. */
    public const float BONUS = 3.0;

    /** How hard the shooter hits this group: BONUS for its speciality, otherwise 1. */
    public static function damageFactor(ShipType $shooter, self $target): float
    {
        return match (true) {
            ShipType::Brecher === $shooter && self::Turrets === $target => self::BONUS,
            ShipClass::Fighter === $shooter->shipClass() && \in_array($target, [self::Invaders, self::Bombers], true) => self::BONUS,
            default => 1.0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Invaders => 'Invasionsschiffe',
            self::Bombers => 'Schwere Bomber',
            self::Fighters => 'Jäger',
            self::Frigates => 'Fregatten',
            self::Cruisers => 'Kreuzer',
            self::Battleships => 'Schlachtschiffe',
            self::Turrets => 'Türme',
            self::Civil => 'zivile Schiffe',
        };
    }
}
