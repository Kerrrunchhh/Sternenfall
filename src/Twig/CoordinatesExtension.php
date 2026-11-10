<?php

namespace App\Twig;

use App\Game\BattleReports;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFilter;

/** Turns coordinates like [3:195:9] or [3:196:1-3] in plain text into links to the universe view. */
final readonly class CoordinatesExtension
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    /** Escapes the text and links every coordinate and every shared combat report in it. */
    #[AsTwigFilter('link_coordinates', isSafe: ['html'])]
    public function link(string $text): string
    {
        $text = preg_replace_callback(
            BattleReports::LINK_PATTERN,
            fn (array $m): string => \sprintf(
                '<a class="report-link" href="%1$s">Kampfbericht %2$s</a> <button type="button" class="btn btn-small btn-secondary copy-link" data-copy-link="%1$s">Link kopieren</button>',
                htmlspecialchars($this->urls->generate('app_battle_report', ['hash' => $m[1]])),
                substr($m[1], 0, 8),
            ),
            htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE),
        );

        return preg_replace_callback(
            '/\[(\d+):(\d+):(\d+)(?:-(\d+))?\]/',
            fn (array $m): string => \sprintf(
                '<a class="coords" href="%s#slot-%d" title="Im Universum anzeigen">%s</a>',
                htmlspecialchars($this->urls->generate('app_galaxy', ['galaxy' => (int) $m[1], 'system' => (int) $m[2]])),
                (int) $m[3],
                $m[0],
            ),
            $text,
        );
    }
}
