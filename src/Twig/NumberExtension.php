<?php

namespace App\Twig;

use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Attribute\AsTwigFilter;

/** Numbers in the player's language: 1.234,5 in German, 1,234.5 in English. */
final class NumberExtension
{
    /** @var array<string, \NumberFormatter> */
    private array $formatters = [];

    public function __construct(private readonly LocaleSwitcher $localeSwitcher)
    {
    }

    #[AsTwigFilter('num')]
    public function num(int|float|string|null $value, int $decimals = 0): string
    {
        $key = $this->localeSwitcher->getLocale().'|'.$decimals;
        if (!isset($this->formatters[$key])) {
            $formatter = new \NumberFormatter($this->localeSwitcher->getLocale(), \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $this->formatters[$key] = $formatter;
        }

        return (string) $this->formatters[$key]->format((float) $value);
    }
}
