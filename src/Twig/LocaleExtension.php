<?php

namespace App\Twig;

use App\I18n\JsMessages;
use App\I18n\Locales;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

final readonly class LocaleExtension
{
    public function __construct(
        private Locales $locales,
        private TranslatorInterface $translator,
    ) {
    }

    /** @return array<string, string> German text => translation, for the browser script */
    #[AsTwigFunction('js_i18n')]
    public function jsMessages(): array
    {
        $out = [];
        foreach (JsMessages::all() as $message) {
            // Placeholders stay in, the script fills them in.
            $out[$message->getMessage()] = $this->translator->trans($message->getMessage(), array_combine(
                array_map(static fn (string $n): string => '{'.$n.'}', $names = self::placeholders($message->getMessage())),
                array_map(static fn (string $n): string => '{'.$n.'}', $names),
            ) ?: []);
        }

        return $out;
    }

    /** @return list<string> */
    private static function placeholders(string $message): array
    {
        preg_match_all('/\{(\w+)\}/', $message, $m);

        return $m[1];
    }

    /** @return array<string, string> locale => name in its own language */
    #[AsTwigFunction('locale_names')]
    public function names(): array
    {
        return $this->locales->names();
    }
}
