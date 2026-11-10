<?php

namespace App\I18n;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Text that is not translated (player content, names) where a translatable is expected. */
final readonly class Raw implements TranslatableInterface
{
    public function __construct(private string $text)
    {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->text;
    }
}
