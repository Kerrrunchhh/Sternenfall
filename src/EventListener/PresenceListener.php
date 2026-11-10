<?php

namespace App\EventListener;

use App\Entity\User;
use App\Game\Presence;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/** Every page view of a logged-in player refreshes his online status. */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
final readonly class PresenceListener
{
    public function __construct(
        private Security $security,
        private Presence $presence,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        // Looking around with the account switcher does not make the other player appear online.
        if (!$user instanceof User || $this->security->getToken() instanceof SwitchUserToken) {
            return;
        }
        $this->presence->touch($user);
    }
}
