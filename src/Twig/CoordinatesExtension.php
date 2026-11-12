<?php

namespace App\Twig;

use App\Game\BattleReports;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;

/** Turns coordinates like [3:195:9] or [3:196:1-3], shared combat reports and web addresses in plain text into links. */
final readonly class CoordinatesExtension
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /** Web addresses in (already escaped) text; trailing punctuation and quotes are not part of them. */
    private const string URL_PATTERN = '~https?://[^\s<>]+?(?=(?:[.,;:!?)\]]|&quot;|&#039;)*(?:\s|$|<))~u';

    /** Escapes the text and links every coordinate, every shared combat report and every web address in it. */
    #[AsTwigFilter('link_coordinates', isSafe: ['html'])]
    public function link(string $text): string
    {
        // Combat reports first (they get a copy button); every other address becomes a plain link.
        $report = substr(BattleReports::LINK_PATTERN, 1, -1);
        $url = substr(self::URL_PATTERN, 1, -2);
        $text = preg_replace_callback(
            '~'.$report.'|'.$url.'~u',
            fn (array $m): string => isset($m[1]) && '' !== $m[1]
                ? \sprintf(
                    '<a class="report-link" href="%1$s">%2$s</a> <button type="button" class="btn btn-small btn-secondary copy-link" data-copy-link="%1$s">%3$s</button>',
                    htmlspecialchars($this->urls->generate('app_battle_report', ['hash' => $m[1]])),
                    htmlspecialchars($this->translator->trans('Kampfbericht {id}', ['{id}' => substr($m[1], 0, 8)])),
                    htmlspecialchars($this->translator->trans('Link kopieren')),
                )
                // $m[0] is already escaped, so it can go into the attribute as it is.
                : \sprintf('<a href="%1$s" target="_blank" rel="noopener nofollow ugc">%1$s</a>', $m[0]),
            htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE),
        );

        return preg_replace_callback(
            '/\[(\d+):(\d+):(\d+)(?:-(\d+))?\]/',
            fn (array $m): string => \sprintf('<a class="coords" href="%s#slot-%d" title="%s">%s</a>', htmlspecialchars($this->urls->generate('app_galaxy', ['galaxy' => (int) $m[1], 'system' => (int) $m[2]])), (int) $m[3], htmlspecialchars($this->translator->trans('Im Universum anzeigen')), $m[0]),
            $text,
        );
    }
}
