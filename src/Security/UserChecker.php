<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Nobody logs in as the owner of ownerless planets; new players confirm their e-mail first. */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }
        if ($user->isSystem()) {
            throw new CustomUserMessageAccountStatusException('Mit diesem Konto kann man sich nicht anmelden.');
        }
        if ($user->isDeactivated()) {
            throw new CustomUserMessageAccountStatusException('Dieses Konto wurde von der Spielleitung deaktiviert. Bei Fragen schreib uns – die Adresse steht im Impressum.');
        }
        // Only new accounts that never played wait for the confirmation; others may add an address later.
        if ($user->needsEmailConfirmation() && null === $user->getLastSeenAt()) {
            throw new CustomUserMessageAccountStatusException('Bitte bestätige zuerst deine E-Mail-Adresse – den Link haben wir dir geschickt. Keine Mail bekommen? Unter „Bestätigungsmail erneut senden“ kannst du sie neu anfordern.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?\Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token = null): void
    {
    }
}
