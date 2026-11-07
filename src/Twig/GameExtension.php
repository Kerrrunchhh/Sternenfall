<?php

namespace App\Twig;

use App\Game\TradeDeal;
use Twig\Attribute\AsTwigFilter;

final class GameExtension
{
    /** Display name of a tradeable good (resource type value or "fusionator"). */
    #[AsTwigFilter('good_label')]
    public static function goodLabel(string $good): string
    {
        return TradeDeal::label($good);
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
