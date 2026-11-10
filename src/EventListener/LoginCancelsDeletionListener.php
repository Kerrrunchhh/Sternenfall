<?php

namespace App\EventListener;

use App\Entity\User;
use App\Game\AccountDeletion;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** Logging in again within the 48 hours keeps the account. */
#[AsEventListener]
final readonly class LoginCancelsDeletionListener
{
    public function __construct(private AccountDeletion $deletion)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User || null === $user->getDeleteAt()) {
            return;
        }
        $this->deletion->cancel($user);
        $session = $event->getRequest()->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', 'Willkommen zurück! Die Löschung deines Kontos wurde abgebrochen.');
        }
    }
}
