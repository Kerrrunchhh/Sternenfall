<?php

namespace App\Game;

/** Relations between two alliances. */
enum TreatyKind: string
{
    case Bond = 'bond';
    case Nap = 'nap';
    case War = 'war';

    public function label(): string
    {
        return match ($this) {
            self::Bond => 'Bündnis',
            self::Nap => 'Nichtangriffspakt',
            self::War => 'Krieg',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Bond => '🤝',
            self::Nap => '🕊',
            self::War => '⚔',
        };
    }

    public function effect(): string
    {
        return match ($this) {
            self::Bond => 'Ihr dürft Schiffe auf allen Planeten der anderen Allianz landen und greift euch nicht an.',
            self::Nap => 'Ihr könnt weder die Planeten noch die Schiffe der anderen Allianz angreifen.',
            self::War => 'Invadierte Planeten der anderen Allianz belegen nur 2 statt 3 Planetenplätze, bis sie ganz euch gehören.',
        };
    }

    /** Peaceful relations forbid attacks of any kind between the two alliances. */
    public function forbidsAttacks(): bool
    {
        return self::War !== $this;
    }

    /** War is declared by one side; bond and NAP need both alliances. */
    public function needsBothSides(): bool
    {
        return self::War !== $this;
    }
}
