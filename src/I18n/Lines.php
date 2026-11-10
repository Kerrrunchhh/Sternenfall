<?php

namespace App\I18n;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Several translated parts joined into one text (a report, a list of results).
 * Plain strings are taken as they are (names, player content), translatables are translated.
 */
final readonly class Lines implements TranslatableInterface
{
    /** @param iterable<string|TranslatableInterface|null> $parts nulls are skipped, '' gives an empty line */
    public function __construct(
        private iterable $parts,
        private string $separator = "\n",
    ) {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        $out = [];
        foreach ($this->parts as $part) {
            if (null !== $part) {
                $out[] = $part instanceof TranslatableInterface ? $part->trans($translator, $locale) : $part;
            }
        }

        return implode($this->separator, $out);
    }

    public function isEmpty(): bool
    {
        foreach ($this->parts as $part) {
            if (null !== $part) {
                return false;
            }
        }

        return true;
    }
}
