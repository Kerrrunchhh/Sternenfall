<?php

namespace App\Game;

use App\Entity\Fleet;
use App\Entity\User;
use App\Repository\FleetRepository;

/**
 * No pin-prick attacks: the ships a player sends into an attack must be at least 1 % of
 * his whole fleet strength (build time of all his ships, defenses excluded) or at least
 * 10 % of his ships of one class (fighters, frigates, cruisers, battleships).
 */
final readonly class AttackRules
{
    public const float MIN_SHARE_OF_STRENGTH = 0.01;
    public const float MIN_SHARE_OF_CLASS = 0.10;

    public function __construct(private FleetRepository $fleets)
    {
    }

    /** @param array<string, int> $ships */
    public static function strength(array $ships): float
    {
        $total = 0.0;
        foreach ($ships as $type => $count) {
            $shipType = ShipType::tryFrom((string) $type);
            if (null !== $shipType && !$shipType->isStationary()) {
                $total += $count * Economy::baseShipHours($shipType);
            }
        }

        return $total;
    }

    /** @return array<string, int> all ships of the player: hangars and fleets */
    public function allShips(User $player): array
    {
        $all = [];
        $add = static function (array $ships) use (&$all): void {
            foreach ($ships as $type => $count) {
                $all[$type] = ($all[$type] ?? 0) + $count;
            }
        };
        foreach ($player->getPlanets() as $planet) {
            $add($planet->getShips());
        }
        foreach ($this->fleets->findByOwner($player) as $fleet) {
            $add($fleet->getShips());
        }

        return $all;
    }

    /** Throws if the fleet is too small a part of its owner's forces to attack. */
    public function assertMayAttack(Fleet $fleet): void
    {
        $sent = $fleet->getShips();
        $all = $this->allShips($fleet->getOwner());
        if (null !== $reason = self::rejection($sent, $all)) {
            throw new GameException($reason);
        }
    }

    /**
     * @param array<string, int> $sent ships going into the attack
     * @param array<string, int> $all  all ships of the player (including those sent)
     */
    public static function rejection(array $sent, array $all): ?string
    {
        $total = self::strength($all);
        $share = $total > 0 ? self::strength($sent) / $total : 1.0;
        if ($share >= self::MIN_SHARE_OF_STRENGTH - 1e-9) {
            return null;
        }

        $best = null;
        foreach (ShipClass::cases() as $class) {
            $owned = $inFleet = 0;
            foreach ($all as $type => $count) {
                if (ShipType::tryFrom((string) $type)?->shipClass() === $class) {
                    $owned += $count;
                    $inFleet += $sent[$type] ?? 0;
                }
            }
            if ($owned > 0 && $inFleet > 0) {
                if ($inFleet / $owned >= self::MIN_SHARE_OF_CLASS - 1e-9) {
                    return null;
                }
                $best = max($best ?? 0, $inFleet / $owned);
            }
        }

        return \sprintf(
            'Zu kleiner Angriff: Die Flotte macht nur %s %% deiner gesamten Flottenstärke aus%s. Nötig sind mindestens 1 %% deiner Flottenstärke oder 10 %% deiner Schiffe einer Klasse (Jäger, Fregatten, Kreuzer, Schlachtschiffe).',
            number_format(100 * $share, 2, ',', '.'),
            null === $best ? '' : \sprintf(' und höchstens %s %% einer Schiffsklasse', number_format(100 * $best, 1, ',', '.')),
        );
    }
}
