<?php

namespace App\Game;

use App\Entity\Alliance;
use App\Entity\User;

/**
 * Who may buy from a trader: everybody if nothing is restricted, otherwise players
 * whose life form or alliance is on the list. The seller himself always may.
 */
final class BuyerAccess
{
    /**
     * @param list<string>       $lifeForms life form values
     * @param iterable<Alliance> $alliances
     */
    public static function allows(User $seller, User $buyer, array $lifeForms, iterable $alliances): bool
    {
        if ($seller->isSameAs($buyer)) {
            return true;
        }
        $alliances = [...$alliances];
        if ([] === $lifeForms && [] === $alliances) {
            return true;
        }
        if (\in_array($buyer->getLifeForm()->value, $lifeForms, true)) {
            return true;
        }
        $own = $buyer->getAlliance();
        foreach ($alliances as $allowed) {
            if (null !== $own && ($allowed === $own || (null !== $allowed->getId() && $allowed->getId() === $own->getId()))) {
                return true;
            }
        }

        return false;
    }
}
