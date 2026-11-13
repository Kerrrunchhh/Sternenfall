<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Passwort vergessen": a signed link that is valid for VALID and only once – it carries a
 * fingerprint of the current password hash, so it stops working as soon as the password changes.
 */
final readonly class PasswordReset
{
    public const string VALID = 'PT1H';

    public function __construct(
        private UriSigner $signer,
        private UrlGeneratorInterface $urls,
        private UserRepository $users,
    ) {
    }

    public function link(User $user): string
    {
        $url = $this->urls->generate('app_reset_password', ['id' => $user->getId(), 'v' => self::fingerprint($user)], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->signer->sign($url, new \DateInterval(self::VALID));
    }

    /** The player the link of the request belongs to, or null if it is forged, expired or used. */
    public function userFor(Request $request): ?User
    {
        if (!$this->signer->checkRequest($request)) {
            return null;
        }
        $id = $request->query->getString('id');
        $user = ctype_digit($id) ? $this->users->find((int) $id) : null;
        if (null === $user || $user->isSystem() || !hash_equals(self::fingerprint($user), $request->query->getString('v'))) {
            return null;
        }

        return $user;
    }

    private static function fingerprint(User $user): string
    {
        return substr(hash('sha256', (string) $user->getPassword()), 0, 16);
    }
}
