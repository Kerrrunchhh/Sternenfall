<?php

namespace App\EventListener;

use App\Entity\User;
use App\Game\MultiAccounts;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** Remembers IP, browser and the device fingerprint the login form sent along. */
#[AsEventListener]
final readonly class LoginRecordListener
{
    public function __construct(private MultiAccounts $multis)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        $request = $event->getRequest();
        if (!$user instanceof User || null !== $event->getPreviousToken()) {
            return;
        }
        $this->multis->recordLogin($user, $request->getClientIp(), $request->headers->get('User-Agent'), $request->request->getString('device_fingerprint') ?: null);
    }
}
