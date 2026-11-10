<?php

namespace App\Twig;

use App\Entity\User;
use App\Game\Diplomacy;
use App\Game\TreatyKind;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/** Diplomacy as seen by the logged-in player. */
final readonly class DiplomacyExtension
{
    public function __construct(
        private Diplomacy $diplomacy,
        private Security $security,
    ) {
    }

    /** Whether the player may attack this player's planets and fleets. */
    #[AsTwigFunction('may_attack')]
    public function mayAttack(User $other): bool
    {
        $viewer = $this->security->getUser();

        return $viewer instanceof User && $this->diplomacy->mayAttack($viewer, $other);
    }

    /** Bond, NAP or war between the viewer's alliance and that of the other player. */
    #[AsTwigFunction('treaty_with')]
    public function treatyWith(User $other): ?TreatyKind
    {
        $viewer = $this->security->getUser();

        return $viewer instanceof User ? $this->diplomacy->kindBetween($viewer, $other) : null;
    }
}
