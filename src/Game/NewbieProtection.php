<?php

namespace App\Game;

use App\Entity\Planet;
use App\Entity\User;

use function Symfony\Component\Translation\t;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Newbie protection: as long as a player has only one planet and no fleet control
 * center, nobody else may attack him, station ships at him or send him resources.
 * Spying stays allowed, and so do paid deliveries for his transport requests. Inactive
 * players (User::isInactive()) have no newbie protection.
 */
final class NewbieProtection
{
    /** Missions a foreign fleet must not fly against a protected player. */
    public const array BLOCKED = [FleetMission::Attack, FleetMission::Station, FleetMission::Jump, FleetMission::Transport, FleetMission::TransportStation];

    public static function isProtected(User $player): bool
    {
        // Inactive players lose it: otherwise accounts left right after signing up would stay untouchable.
        return 1 === $player->getPlanets()->count() && 0 === Economy::fleetCapacity($player)
            && !$player->isInactive(\Symfony\Component\Clock\now());
    }

    /** Why the mission is refused, or null if it may fly. */
    public static function refusal(FleetMission $mission, User $sender, ?Planet $target): ?TranslatableInterface
    {
        if (null === $target || $target->getOwner()->isSameAs($sender) || !\in_array($mission, self::BLOCKED, true) || !self::isProtected($target->getOwner())) {
            return null;
        }

        return t('{player} steht unter Noobschutz: Solange jemand nur einen Planeten und kein Flottenkontrollzentrum hat, darf niemand ihn angreifen, bei ihm stationieren oder ihm Rohstoffe schicken. Rohstoffe bekommt er über Transportgesuche.', ['{player}' => $target->getOwner()->getUsername()]);
    }
}
