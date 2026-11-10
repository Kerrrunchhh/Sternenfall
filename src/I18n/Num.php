<?php

namespace App\I18n;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A number inside a translated message, formatted for the reader's language
 * (1.234 in German, 1,234 in English): t('Du hast {credits} Credits.', ['{credits}' => new Num($c)]).
 */
final readonly class Num implements TranslatableInterface
{
    public function __construct(
        private int|float $value,
        private int $decimals = 0,
    ) {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        $formatter = new \NumberFormatter($locale ?? $translator->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $this->decimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $this->decimals);

        return (string) $formatter->format($this->value);
    }
}
