<?php

namespace App\Game;

use App\I18n\When;
use App\I18n\Lines;
use App\Entity\Planet;
use App\Repository\TradePostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

use function Symfony\Component\Translation\t;

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
    /**
     * $admin: the game team changes it (corrections) – no hero point rules and no cooldown, and the
     * player's own 30 days are not restarted; he gets a message. Alliances still hold one life form.
     */
    public function change(Planet $planet, LifeForm $lifeForm, ?\App\Entity\User $admin = null): void
    {
        $lock = $this->lockFactory->createLock('fleet_events');
        $lock->acquire(true);
        try {
            $this->planets->transaction($planet, function (Planet $planet, \DateTimeImmutable $now) use ($lifeForm, $admin): void {
                $player = $planet->getOwner();
                if ($player->getLifeForm() === $lifeForm) {
                    throw new GameException(null !== $admin
                        ? t('{player} gehört bereits zu den {lifeForm}.', ['{player}' => $player->getDisplayName(), '{lifeForm}' => t($lifeForm->label())])
                        : t('Du gehörst bereits zu den {lifeForm}.', ['{lifeForm}' => t($lifeForm->label())]));
                }
                // An alliance only has players of one life form.
                if (null !== $alliance = $player->getAlliance()) {
                    throw new GameException(null !== $admin
                        ? t('{player} ist in „{name}“ ({type}) – dort sind nur {label}. Er muss erst austreten (oder entfernt werden).', ['{player}' => $player->getDisplayName(), '{name}' => $alliance->getName(), '{type}' => $alliance->getType(), '{label}' => t($alliance->getLifeForm()->label())])
                        : t('Du bist in „{name}“ ({type}) – dort sind nur {label}. Tritt erst aus, dann kannst du die Lebensform wechseln.', ['{name}' => $alliance->getName(), '{type}' => $alliance->getType(), '{label}' => t($alliance->getLifeForm()->label())]));
                }
                if (null === $admin && null !== $refusal = HeroPoints::lifeFormChangeRefusal($player->getLifeForm(), $lifeForm, $player->getHeroPoints($now))) {
                    throw new GameException($refusal);
                }
                if (null === $admin && $player->getNextLifeFormChangeAt() > $now) {
                    throw new GameException(t('Ein Wechsel ist erst ab {value} möglich.', ['{value}' => new When($player->getNextLifeFormChangeAt())]));
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
                        $this->inbox->send($player, t('Handelsstützpunkte aufgegeben'), new Lines([t('Mit dem Wechsel der Lebensform verkaufen deine Handelsstützpunkte nichts mehr. Die Ware bleibt im Lager; hol sie mit einer Flotte (Auftrag „Handeln“) ab. Leere Stützpunkte verschwinden.'), ...$abandoned]));
                    }
                }

                if (null !== $admin) {
                    $old = $player->getLifeForm();
                    $player->changeLifeForm($lifeForm, $player->getLifeFormSince());
                    $this->inbox->send($player, t('Deine Lebensform wurde geändert'), t('Die Spielleitung hat deine Lebensform von {old} auf {new} geändert. Deine eigene Wechselfrist läuft unverändert weiter.', ['{old}' => t($old->label()), '{new}' => t($lifeForm->label())]));

                    return;
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
