<?php

namespace App\Tests\Game;

use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\Translation\TranslatableInterface;

/** Game messages are translatables: tests read them as the German text. */
trait RendersMessages
{
    private static function text(TranslatableInterface|string|null $message): string
    {
        if (null === $message || \is_string($message)) {
            return (string) $message;
        }
        $translator = new IdentityTranslator();
        $translator->setLocale('de');

        return $message->trans($translator, 'de');
    }
}
