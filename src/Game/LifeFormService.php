<?php

namespace App\Game;

use App\Entity\Planet;
use App\Repository\TradePostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

final readonly class LifeFormService
{
    public function __construct(
        private PlanetService $planets,
        private TradePostRepository $tradePosts,
        private EntityManagerInterface $em,
        private Inbox $inbox,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * Switches the life form of the planet's owner. Production up to now is
     * settled with the old life form first (the transaction synchronizes all planets).
     * A trader who changes abandons all trade posts: nothing is sold any more, but the
     * stock stays and can still be collected by the owner. Empty posts disappear.
     *
     * Takes the fleet event lock first (trade post stock is changed by fleet
     * arrivals), then the player lock, like every other fleet-related operation.
     */
    public function change(Planet $planet, LifeForm $lifeForm): void
    {
        $lock = $this->lockFactory->createLock('fleet_events');
        $lock->acquire(true);
        try {
            $this->planets->transaction($planet, function (Planet $planet, \DateTimeImmutable $now) use ($lifeForm): void {
                $player = $planet->getOwner();
                if ($player->getLifeForm() === $lifeForm) {
                    throw new GameException(\sprintf('Du gehörst bereits zu den %s.', $lifeForm->label()));
                }
                // An alliance only has players of one life form.
                if (null !== $alliance = $player->getAlliance()) {
                    throw new GameException(\sprintf('Du bist in „%s“ (%s) – dort sind nur %s. Tritt erst aus, dann kannst du die Lebensform wechseln.', $alliance->getName(), $alliance->getType(), $alliance->getLifeForm()->label()));
                }
                if ($player->getNextLifeFormChangeAt() > $now) {
                    throw new GameException(\sprintf('Ein Wechsel ist erst ab %s möglich.', $player->getNextLifeFormChangeAt()->format('d.m.Y H:i')));
                }

                if (LifeForm::Trader === $player->getLifeForm()) {
                    $abandoned = [];
                    foreach ($this->tradePosts->findBy(['owner' => $player, 'active' => true]) as $post) {
                        if ($post->isEmpty()) {
                            $this->em->remove($post);
                            continue;
                        }
                        $post->abandon();
                        $abandoned[] = \sprintf('  %s [%s]', $post->getName(), $post->getCoordinates());
                    }
                    if ([] !== $abandoned) {
                        $this->inbox->send($player, 'Handelsstützpunkte aufgegeben', "Mit dem Wechsel der Lebensform verkaufen deine Handelsstützpunkte nichts mehr. Die Ware bleibt im Lager; hol sie mit einer Flotte (Auftrag „Handeln“) ab. Leere Stützpunkte verschwinden.\n".implode("\n", $abandoned));
                    }
                }

                $player->changeLifeForm($lifeForm, $now);
            });
        } finally {
            $lock->release();
        }
    }

    /** Number of trade posts that a life form change would abandon. */
    public function postsLostOnChange(Planet $planet): int
    {
        return LifeForm::Trader === $planet->getOwner()->getLifeForm() ? $this->tradePosts->countByOwner($planet->getOwner()) : 0;
    }
}
