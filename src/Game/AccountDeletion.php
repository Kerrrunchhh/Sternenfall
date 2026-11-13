<?php

namespace App\Game;

use App\Entity\DeletedAccount;
use App\Entity\Fleet;
use App\Entity\TradePost;
use App\Entity\User;
use App\Repository\FleetRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

use function Symfony\Component\Translation\t;

/**
 * Deleting an account: 48 hours after the request (logging in again cancels it) the
 * account is gone. Its planets stay as ownerless planets – with resources, ships and
 * defenses, with or without buildings as the player chose – and can be attacked and
 * invaded. Fleets and trade posts are left behind as well.
 */
final readonly class AccountDeletion
{
    public const string DELAY = '+48 hours';
    public const string NOBODY = 'Herrenlos';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private FleetRepository $fleets,
        private AllianceService $alliances,
        private ClockInterface $clock,
    ) {
    }

    public function request(User $user, bool $destroyBuildings): \DateTimeImmutable
    {
        if ($user->isSystem()) {
            throw new GameException(t('Dieses Konto kann nicht gelöscht werden.'));
        }
        $at = $this->now()->modify(self::DELAY);
        $user->requestDeletion($at, $destroyBuildings);
        $this->em->flush();

        return $at;
    }

    public function cancel(User $user): void
    {
        $user->cancelDeletion();
        $this->em->flush();
    }

    /** Deletes every account whose 48 hours are over. */
    public function deleteDue(): void
    {
        $due = $this->users->createQueryBuilder('u')
            ->where('u.deleteAt IS NOT NULL AND u.deleteAt <= :now AND u.system = false')
            ->setParameter('now', $this->now())
            ->getQuery()->getResult();
        foreach ($due as $user) {
            $this->delete($user);
        }
    }

    public function delete(User $user): void
    {
        $now = $this->now();
        $this->abandon($user, $user->isDeleteDestroyingBuildings());

        $this->em->persist(new DeletedAccount($user->getUsername(), $now));
        $this->em->remove($user);
        $this->em->flush();
    }

    /**
     * The player leaves his alliance and hands planets, fleets and trade posts to nobody
     * (not flushed) – for deleting and for deactivating an account.
     */
    public function abandon(User $user, bool $destroyBuildings): void
    {
        $nobody = $this->nobody();
        $now = $this->now();

        $this->leaveAlliance($user);

        foreach ($user->getPlanets()->toArray() as $planet) {
            if ($user->isResearchingOn($planet)) {
                $user->clearResearch();
            }
            $planet->leaveToNobody($nobody, $destroyBuildings);
        }
        foreach ($this->fleets->findByOwner($user) as $fleet) {
            if ($fleet->isMoving() && !$fleet->isReturning()) {
                $fleet->recall($now);
            }
            $fleet->handOverTo($nobody);
        }
        foreach ($this->em->getRepository(TradePost::class)->findBy(['owner' => $user]) as $post) {
            $post->handOverTo($nobody);
        }
        // Fleets of others released to him lose that release.
        foreach ($this->em->getRepository(Fleet::class)->findAll() as $fleet) {
            if ($fleet->isReleasedTo($user)) {
                $fleet->revokeFrom($user);
            }
        }
    }

    /** The owner of all ownerless planets, created on first use. */
    public function nobody(): User
    {
        $nobody = $this->users->findOneBy(['system' => true, 'username' => self::NOBODY]);
        if (null === $nobody) {
            $nobody = new User(self::NOBODY, $this->now());
            $nobody->setPassword('!'); // no password hash matches: nobody can log in
            $nobody->markSystem();
            $this->em->persist($nobody);
            $this->em->flush();
        }

        return $nobody;
    }

    private function leaveAlliance(User $user): void
    {
        $alliance = $user->getAlliance();
        if (null === $alliance) {
            return;
        }
        if (AllianceRank::Founder !== $user->getAllianceRank()) {
            $this->alliances->leave($user);

            return;
        }
        $others = array_values(array_filter($alliance->getMembers()->toArray(), static fn (User $m): bool => !$m->isSameAs($user)));
        if ([] === $others) {
            $this->alliances->dissolve($user);

            return;
        }
        // The highest rank takes over the alliance.
        $order = [AllianceRank::Commander, AllianceRank::Member, AllianceRank::Prospect];
        usort($others, static fn (User $a, User $b): int => array_search($a->getAllianceRank(), $order, true) <=> array_search($b->getAllianceRank(), $order, true));
        $this->alliances->transferFounder($user, $others[0]);
        $this->alliances->leave($user);
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
