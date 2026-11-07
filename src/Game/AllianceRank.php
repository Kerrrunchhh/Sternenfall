<?php

namespace App\Game;

/**
 * Ranks inside an alliance, from top to bottom.
 */
enum AllianceRank: string
{
    case Founder = 'founder';
    case Commander = 'commander';
    case Member = 'member';
    case Prospect = 'prospect';

    public function label(): string
    {
        return match ($this) {
            self::Founder => 'Gründer',
            self::Commander => 'Kommandant',
            self::Member => 'Mitglied',
            self::Prospect => 'Anwärter',
        };
    }

    /** The founder may do everything: members, ranks, description, dissolving. */
    public function canManage(): bool
    {
        return self::Founder === $this;
    }

    /** May see and control the fleets members released to the alliance. */
    public function hasFleetRights(): bool
    {
        return self::Founder === $this || self::Commander === $this;
    }

    /** Only the rank directly below the founder may vote to depose the founder and be elected as successor. */
    public function canVoteOnFounder(): bool
    {
        return self::Commander === $this;
    }

    public function canSeeBoard(): bool
    {
        return self::Prospect !== $this;
    }

    /** Ranks the founder can assign (there is only one founder; it is handed over separately). */
    public static function assignable(): array
    {
        return [self::Commander, self::Member, self::Prospect];
    }
}
