<?php

namespace App\Game;

use App\Entity\Message;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Delivers system messages to players (persisted, flushed by the caller), written in
 * the recipient's language: subject and text are t('…', [...]) messages.
 */
final readonly class Inbox
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private TranslatorInterface $translator = new IdentityTranslator(),
    ) {
    }

    /**
     * @param string|null                $actionUrl   optional button below the message: a path in the game
     * @param TranslatableInterface|null $actionLabel its text (in the recipient's language)
     */
    public function send(User $recipient, TranslatableInterface $subject, TranslatableInterface $body, ?string $actionUrl = null, ?TranslatableInterface $actionLabel = null): void
    {
        $locale = $recipient->getLocale();
        // Stored as text: times in it are shown in the recipient's time zone.
        [$subjectText, $bodyText] = \App\I18n\DisplayZone::with($recipient->getTimezone(), fn (): array => [$subject->trans($this->translator, $locale), $body->trans($this->translator, $locale)]);
        $message = new Message($recipient, $subjectText, $bodyText, \DateTimeImmutable::createFromInterface($this->clock->now()));
        if (null !== $actionUrl && null !== $actionLabel) {
            $message->setAction($actionUrl, $actionLabel->trans($this->translator, $locale));
        }
        $this->em->persist($message);
    }
}
