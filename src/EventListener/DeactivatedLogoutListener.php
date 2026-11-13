<?php

namespace App\EventListener;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** A player an admin deactivated is logged out on his next click (login itself is blocked by the UserChecker). */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)] // right after the firewall (8)
final readonly class DeactivatedLogoutListener
{
    public function __construct(private Security $security, private UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isDeactivated()) {
            return;
        }
        $this->security->logout(false);
        $event->setResponse(new RedirectResponse($this->urls->generate('app_login')));
    }
}
