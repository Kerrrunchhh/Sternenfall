<?php

namespace App\EventListener;

use App\Entity\User;
use App\I18n\DisplayZone;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Extension\CoreExtension;

/**
 * Pages show times in the time zone the player chose (visitors: the default zone):
 * Twig's |date filter and translated texts with App\I18n\When alike.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)] // after the firewall (8)
final readonly class TimezoneListener
{
    public function __construct(private Security $security, private Environment $twig)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        DisplayZone::set($user instanceof User ? $user->getTimezone() : DisplayZone::DEFAULT);
        $this->twig->getExtension(CoreExtension::class)->setTimezone(DisplayZone::get());
    }
}
