<?php

namespace App\I18n;

/**
 * Numbers typed by players: German "50.000" / "2,5", English "50,000" / "2.5".
 */
final class NumberInput
{
    /** Whole amounts (credits, resources): every separator is a thousands separator. */
    public static function amount(string $input): float
    {
        $digits = preg_replace('/[\s.,\'’_]/u', '', trim($input)) ?? '';

        return is_numeric($digits) ? (float) $digits : 0.0;
    }

    /** Amounts that may have decimals (prices). */
    public static function decimal(string $input, string $locale): float
    {
        $s = preg_replace('/[\s\'’_]/u', '', trim($input)) ?? '';
        if ('en' === $locale) {
            // "1,000.5" or "1,000": commas group; a lone comma like "2,5" is still read as decimal.
            $s = preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } else {
            // "1.000,5" or "1.000": dots group; "1.5" stays a decimal as before.
            $s = preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s) ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : 0.0;
    }
}
