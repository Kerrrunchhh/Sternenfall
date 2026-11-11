<?php

namespace App\Twig;

use App\Entity\User;
use App\Game\Scoring;
use App\Game\TradeDeal;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final class GameExtension
{
    /** Display name of a tradeable good (resource type value or "fusionator"). */
    #[AsTwigFilter('good_label')]
    public static function goodLabel(string $good): string
    {
        return TradeDeal::label($good);
    }

    /** Points of a player: how far his planets are built up. */
    #[AsTwigFunction('player_points')]
    public static function playerPoints(User $player): int
    {
        return Scoring::points($player);
    }

    /**
     * Totals of an alliance for its public profile.
     *
     * @return array{planets: int, points: int, experience: float, avg_experience: float, avg_points: float}
     */
    #[AsTwigFunction('alliance_stats')]
    public static function allianceStats(\App\Entity\Alliance $alliance): array
    {
        $members = $alliance->getMembers()->toArray();
        $count = max(1, \count($members));
        $planets = array_sum(array_map(static fn (User $u): int => $u->getPlanets()->count(), $members));
        $points = array_sum(array_map(static fn (User $u): int => Scoring::points($u), $members));
        $experience = array_sum(array_map(static fn (User $u): float => $u->getCombatExperience(), $members));

        return ['planets' => $planets, 'points' => $points, 'experience' => $experience, 'avg_experience' => $experience / $count, 'avg_points' => $points / $count];
    }

    /** Until when a moving fleet can still be recalled (Kommunikationstechnik), null if no more. */
    #[AsTwigFunction('recall_until')]
    public static function recallUntil(\App\Entity\Fleet $fleet): ?\DateTimeImmutable
    {
        if (!$fleet->isMoving() || $fleet->isReturning() || null === $fleet->getDepartAt()) {
            return null;
        }
        if (\App\Game\FleetMission::Hover === $fleet->getMission()) {
            return $fleet->getNextEventAt();
        }
        $until = $fleet->getDepartAt()->modify('+'.\App\Game\Economy::recallWindow($fleet->getOwner()).' seconds');

        return $until > new \DateTimeImmutable() ? $until : null;
    }

    /** Points of one planet (building levels × points rate). */
    #[AsTwigFunction('planet_points')]
    public static function planetPoints(\App\Entity\Planet $planet): int
    {
        return Scoring::planetPoints($planet);
    }

    /** Formats seconds as e.g. "1d 02:03:04" or "02:03". */
    #[AsTwigFilter('format_duration')]
    public static function formatDuration(int|float $seconds): string
    {
        $seconds = max(0, (int) $seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        $time = $hours > 0 || $days > 0
            ? \sprintf('%02d:%02d:%02d', $hours, $minutes, $secs)
            : \sprintf('%02d:%02d', $minutes, $secs);

        return $days > 0 ? $days.'d '.$time : $time;
    }
}
