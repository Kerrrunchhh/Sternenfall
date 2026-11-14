<?php

namespace App\Game;

/**
 * The four life forms a player can belong to. Everybody starts as a settler.
 */
enum LifeForm: string
{
    case Settler = 'settler';
    case Guardian = 'guardian';
    case Pirate = 'pirate';
    case Trader = 'trader';

    /** Earliest possible change after starting or after the last change. */
    public const string CHANGE_COOLDOWN = '+30 days';

    public function label(): string
    {
        return match ($this) {
            self::Settler => 'Siedler',
            self::Guardian => 'Wächter',
            self::Pirate => 'Piraten',
            self::Trader => 'Händler',
        };
    }

    /** Small symbol shown next to player names. */
    public function icon(): string
    {
        return match ($this) {
            self::Settler => '🌱',
            self::Guardian => '🛡️',
            self::Pirate => '☠️',
            self::Trader => '💰',
        };
    }

    /** What an alliance founded by this life form is called. */
    public function allianceType(): string
    {
        return match ($this) {
            self::Guardian => 'Orden',
            self::Pirate => 'Syndikat',
            self::Trader => 'Handelsgilde',
            self::Settler => 'Allianz',
        };
    }

    /** @return list<string> */
    public function traits(): array
    {
        return match ($this) {
            self::Settler => ['Schiffe kosten keine Credits'],
            self::Guardian => ['Schiffe fliegen doppelt so schnell (nicht, wenn kriminell)', 'Können angreifende Flotten abfangen', 'Greifen keine Siedler und Händler an, die nicht kriminell sind (außer im Krieg)'],
            self::Pirate => ['Schiffe fliegen 1,5-mal so schnell', 'Können angreifende Flotten abfangen und Transporte überfallen'],
            self::Trader => ['Doppelte Rohstoffproduktion', 'Schiffe kosten 1,5-mal so viel', 'Bauen als Einzige Fusionatoren', 'Können auf ihren Kolonien Handelsstützpunkte einrichten', 'Greifen keine Siedler und Händler an, die nicht kriminell sind (außer im Krieg)'],
        };
    }

    public function speedFactor(): float
    {
        return match ($this) {
            self::Guardian => 2.0,
            self::Pirate => 1.5,
            self::Settler, self::Trader => 1.0,
        };
    }

    public function productionFactor(): float
    {
        return self::Trader === $this ? 2.0 : 1.0;
    }

    /** Multiplier on the resource cost of ships. */
    public function shipResourceFactor(): float
    {
        return self::Trader === $this ? 1.5 : 1.0;
    }

    /** Multiplier on the credit cost of ships. */
    public function shipCreditFactor(): float
    {
        return match ($this) {
            self::Settler => 0.0,
            self::Trader => 1.5,
            self::Guardian, self::Pirate => 1.0,
        };
    }
}
