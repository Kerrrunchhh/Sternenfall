<?php

namespace App\I18n;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The languages of the game: German is the source language, the others are translations. */
final readonly class Locales
{
    public const array NAMES = ['de' => 'Deutsch', 'en' => 'English'];

    /** @param list<string> $enabled */
    public function __construct(
        #[Autowire('%kernel.enabled_locales%')] private array $enabled,
        #[Autowire('%kernel.default_locale%')] private string $default,
    ) {
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->enabled;
    }

    public function default(): string
    {
        return $this->default;
    }

    public function supports(?string $locale): bool
    {
        return null !== $locale && \in_array($locale, $this->enabled, true);
    }

    /** @return array<string, string> locale => name in its own language */
    public function names(): array
    {
        return array_intersect_key(self::NAMES, array_flip($this->enabled));
    }
}
