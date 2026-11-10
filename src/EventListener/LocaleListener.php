<?php

namespace App\EventListener;

use App\Entity\User;
use App\I18n\Locales;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Picks the language of a request: the player's setting; for visitors ?lang=…
 * (remembered in the session), else the browser's preference, else German.
 * Runs after the firewall so the logged-in player is known.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class LocaleListener
{
    public const string SESSION_KEY = '_locale';

    public function __construct(
        private Security $security,
        private Locales $locales,
        private LocaleSwitcher $localeSwitcher,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $user = $this->security->getUser();
        // The language switch (?lang=en) is remembered in the player's account.
        $asked = $request->query->getString('lang');
        if ($user instanceof User && $this->locales->supports($asked) && $asked !== $user->getLocale()) {
            $user->setLocale($asked);
            $this->em->flush();
        }
        if ($user instanceof User && $this->locales->supports($user->getLocale())) {
            $locale = $user->getLocale();
        } else {
            $session = $request->hasSession() ? $request->getSession() : null;
            if ($this->locales->supports($asked)) {
                $session?->set(self::SESSION_KEY, $asked);
            }
            // Only read an existing session: visitors without one don't get a cookie just for this.
            $remembered = $request->hasPreviousSession() ? $session?->get(self::SESSION_KEY) : null;
            $locale = $this->locales->supports($asked) ? $asked
                : ($this->locales->supports($remembered) ? $remembered
                : ($request->getPreferredLanguage($this->locales->all()) ?? $this->locales->default()));
        }
        $request->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);
    }
}
