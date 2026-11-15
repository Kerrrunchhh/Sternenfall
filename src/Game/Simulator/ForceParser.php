<?php

namespace App\Game\Simulator;

use App\Game\ResearchType;
use App\Game\ShipType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads ships and combat research out of pasted text: a spy report (German or English) or a ship
 * list copied from a game page. Lenient on purpose – every line with a known name and a number
 * counts, e.g. "Pfeil: 40", "Pfeil	1.234", "20 × Falke, 5 × Lanze" or "  Impulslaser: Stufe 7".
 * Fleets of other players listed in a report are added to the planet's own units: they fight too.
 */
final readonly class ForceParser
{
    /** @param list<string> $locales */
    public function __construct(
        private TranslatorInterface $translator,
        #[Autowire('%kernel.enabled_locales%')]
        private array $locales,
    ) {
    }

    public function parse(string $text): ParsedForce
    {
        $shipNames = $this->names(ShipType::cases());
        $researchNames = $this->names(CombatResearch::types());
        $ships = [];
        $research = [];
        $owner = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            // "Planet Heimat [1:2:3] von Halden" / "Planet Home [1:2:3] of Halden": whose planet it is.
            if (null === $owner && preg_match('/\[\d+:\d+:\d+\]\s+(?:von|of|by)\s+(\S+)/u', $line, $m)) {
                $owner = $m[1];
                continue;
            }
            $found = $this->research($line, $researchNames);
            if (null !== $found) {
                $research[$found[0]] = $found[1];
                continue;
            }
            foreach ($this->ships($line, $shipNames) as $type => $count) {
                $ships[$type] = ($ships[$type] ?? 0) + $count;
            }
        }

        return new ParsedForce($ships, $research, $owner);
    }

    /** @return array{string, int}|null research value and level, if the line names a combat research */
    private function research(string $line, array $names): ?array
    {
        foreach ($names as $name => $value) {
            if (preg_match('/(?<![\p{L}])'.preg_quote($name, '/').'(?![\p{L}])\D*?(\d+)/iu', $line, $m)) {
                return [$value, (int) $m[1]];
            }
        }

        return null;
    }

    /** @return array<string, int> ship type => count found in the line */
    private function ships(string $line, array $names): array
    {
        $found = [];
        // Several ships in one line are separated by ", " or ";" (a comma inside a number has no space after it).
        foreach (preg_split('/;|,\s+|\s\/\s/u', $line) ?: [] as $part) {
            foreach ($names as $name => $value) {
                $pattern = '/(?<![\p{L}])'.preg_quote($name, '/').'(?![\p{L}])/iu';
                if (!preg_match($pattern, $part, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $before = substr($part, 0, $m[0][1]);
                $after = substr($part, $m[0][1] + \strlen($m[0][0]));
                // "Pfeil: 40", "Pfeil	40", "Pfeil 1.234" – or "40 × Pfeil", "40 Pfeil".
                if (preg_match('/^\s*[:=×x*\t ]*\s*(\d[\d.,\']*\d|\d)/u', $after, $n) || preg_match('/(\d[\d.,\']*\d|\d)\s*[×x*]?\s*$/u', $before, $n)) {
                    $found[$value] = ($found[$value] ?? 0) + (int) preg_replace('/\D/', '', $n[1]);
                }
                // A longer name ("Großfrachter") is tried before the shorter one in it ("Frachter").
                break;
            }
        }

        return $found;
    }

    /**
     * Names in every language, longest first so that "Großfrachter" wins over "Frachter".
     *
     * @param list<ShipType|ResearchType> $cases
     *
     * @return array<string, string> name => enum value
     */
    private function names(array $cases): array
    {
        $names = [];
        foreach ($cases as $case) {
            foreach ($this->locales as $locale) {
                $names[mb_strtolower($this->translator->trans($case->label(), [], null, $locale))] = $case->value;
            }
            $names[mb_strtolower($case->label())] = $case->value;
        }
        uksort($names, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $names;
    }
}
