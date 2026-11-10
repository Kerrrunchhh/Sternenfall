<?php

namespace App\Game;

use App\Entity\Fleet;
use App\Entity\Squadron;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

use function Symfony\Component\Translation\t;

/** Squadrons: named groups of fleets a player can steer (own and released ones). */
final readonly class SquadronService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Adds fleets to a squadron; without one, a new squadron with that name is created.
     *
     * @param list<Fleet> $fleets
     */
    public function add(User $player, ?Squadron $squadron, string $newName, array $fleets): Squadron
    {
        if ([] === $fleets) {
            throw new GameException(t('Wähle mindestens eine Flotte aus.'));
        }
        if (null === $squadron) {
            $squadron = new Squadron($player, $this->validName($newName));
            $this->em->persist($squadron);
        } else {
            $this->assertOwner($squadron, $player);
        }
        foreach ($fleets as $fleet) {
            if (!$fleet->isControllableBy($player) || !$fleet->isNamed()) {
                throw new GameException(t('{label} kannst du keinem Geschwader zuordnen.', ['{label}' => $fleet->getLabel()]));
            }
            $squadron->add($fleet);
        }
        $this->em->flush();

        return $squadron;
    }

    /** @param list<Fleet> $fleets */
    public function remove(User $player, Squadron $squadron, array $fleets): void
    {
        $this->assertOwner($squadron, $player);
        foreach ($fleets as $fleet) {
            $squadron->remove($fleet);
        }
        $this->em->flush();
    }

    /** Deletes the squadron; its fleets stay as they are. */
    public function delete(User $player, Squadron $squadron): void
    {
        $this->assertOwner($squadron, $player);
        $this->em->remove($squadron);
        $this->em->flush();
    }

    private function assertOwner(Squadron $squadron, User $player): void
    {
        if (!$squadron->getOwner()->isSameAs($player)) {
            throw new GameException(t('Das ist nicht dein Geschwader.'));
        }
    }

    private function validName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ('' === $name || mb_strlen($name) > 40) {
            throw new GameException(t('Gib dem neuen Geschwader einen Namen (1 bis 40 Zeichen).'));
        }

        return $name;
    }
}
