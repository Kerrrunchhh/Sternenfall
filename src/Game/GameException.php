<?php

namespace App\Game;

use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A rule violation whose message is safe to show to the player.
 *
 * The message is a translation key (German text, ICU placeholders like {credits}),
 * created with t() so translation:extract finds it: throw new GameException(t('…', [...])).
 * Plain strings still work and are treated as keys without parameters.
 */
class GameException extends \DomainException implements TranslatableInterface
{
    private readonly TranslatableInterface $translatable;

    public function __construct(string|TranslatableInterface $message, ?\Throwable $previous = null)
    {
        $this->translatable = $message instanceof TranslatableInterface ? $message : new TranslatableMessage($message);
        parent::__construct($this->translatable instanceof TranslatableMessage ? $this->translatable->getMessage() : $message::class, 0, $previous);
    }

    /** For flash messages and other places that keep the message beyond the request (serialisable). */
    public function translatable(): TranslatableInterface
    {
        return $this->translatable;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->translatable->trans($translator, $locale);
    }
}
