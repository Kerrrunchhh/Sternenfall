<?php

namespace App\I18n;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A moment inside a translated message, shown in the reader's time zone (DisplayZone):
 * t('Urlaub bis {until}', ['{until}' => new When($until)]). Null shows as "–".
 */
final readonly class When implements TranslatableInterface
{
    public function __construct(
        private ?\DateTimeInterface $moment,
        private string $format = 'd.m.Y H:i',
    ) {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return null === $this->moment ? '–' : DisplayZone::local($this->moment)->format($this->format);
    }
}
