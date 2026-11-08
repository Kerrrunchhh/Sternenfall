<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\UserRepository;
use Twig\Attribute\AsTwigFunction;

/** Accounts offered by the account switcher (only rendered for ROLE_ALLOWED_TO_SWITCH). */
final readonly class AccountSwitchExtension
{
    public function __construct(private UserRepository $users)
    {
    }

    /** @return list<string> all player names, alphabetically */
    #[AsTwigFunction('switchable_accounts')]
    public function accounts(): array
    {
        return array_map(
            static fn (User $user): string => $user->getUsername(),
            $this->users->findBy([], ['username' => 'ASC']),
        );
    }
}
