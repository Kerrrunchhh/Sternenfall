<?php

namespace App\Twig;

use App\Entity\User;
use App\Game\Presence;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/** Online status of a player, only for those allowed to see it (alliance mates, quick contacts). */
final readonly class PresenceExtension
{
    public function __construct(
        private Presence $presence,
        private Security $security,
    ) {
    }

    /** @return array{online: bool, text: string}|null null when the viewer may not see it */
    #[AsTwigFunction('presence')]
    public function presence(User $user): ?array
    {
        $viewer = $this->security->getUser();
        if (!$viewer instanceof User || !$this->presence->isVisibleTo($viewer, $user)) {
            return null;
        }

        return ['online' => $this->presence->isOnline($user), 'text' => $this->presence->describe($user)];
    }
}
