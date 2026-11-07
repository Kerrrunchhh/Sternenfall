<?php

namespace App\Repository;

use App\Entity\Fleet;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fleet>
 */
class FleetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fleet::class);
    }

    /**
     * Moving fleets that arrive or return no later than $now, in chronological order.
     *
     * @return list<Fleet>
     */
    public function findDue(\DateTimeImmutable $now): array
    {
        $fleets = $this->createQueryBuilder('f')
            ->where('f.mission IS NOT NULL')
            ->andWhere('(f.returning = false AND f.arriveAt <= :now) OR (f.returning = true AND f.returnAt <= :now)')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        usort($fleets, static fn (Fleet $a, Fleet $b): int => [$a->getNextEventAt(), $a->getId()] <=> [$b->getNextEventAt(), $b->getId()]);

        return $fleets;
    }

    /**
     * All fleets of a player: named fleets first (by name), then quick dispatches.
     *
     * @return list<Fleet>
     */
    public function findByOwner(User $owner): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.owner = :owner')
            ->setParameter('owner', $owner)
            ->addSelect('CASE WHEN f.name IS NULL THEN 1 ELSE 0 END AS HIDDEN unnamed')
            ->orderBy('unnamed', 'ASC')
            ->addOrderBy('f.name', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes a fleet. Fleets that were heading for it (interceptors, gathering
     * groups) lose their target first; they notice on arrival and turn back.
     */
    public function forget(Fleet $fleet): void
    {
        if (null !== $fleet->getId()) {
            foreach ($this->findBy(['targetFleet' => $fleet]) as $follower) {
                $follower->forgetTargetFleet();
            }
        }
        $this->getEntityManager()->remove($fleet);
    }

    /** @return list<Fleet> moving fleets of all other players */
    public function findMovingOfOthers(User $player): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.mission IS NOT NULL AND f.owner <> :player')
            ->setParameter('player', $player)
            ->orderBy('f.arriveAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Waiting fleets at a position that defend it: those of the given players.
     *
     * @param list<User> $owners
     *
     * @return list<Fleet>
     */
    public function findIdleOf(int $galaxy, int $system, int $position, array $owners): array
    {
        if ([] === $owners) {
            return [];
        }

        return $this->createQueryBuilder('f')
            ->where('f.galaxy = :g AND f.system = :s AND f.position = :p AND f.mission IS NULL AND f.owner IN (:owners)')
            ->setParameter('g', $galaxy)
            ->setParameter('s', $system)
            ->setParameter('p', $position)
            ->setParameter('owners', $owners)
            ->getQuery()
            ->getResult();
    }

    /**
     * Fleets of other players waiting at a position.
     *
     * @return list<Fleet>
     */
    public function findForeignIdleAt(int $galaxy, int $system, int $position, User $except): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.galaxy = :g AND f.system = :s AND f.position = :p AND f.mission IS NULL AND f.owner <> :except')
            ->setParameter('g', $galaxy)
            ->setParameter('s', $system)
            ->setParameter('p', $position)
            ->setParameter('except', $except)
            ->getQuery()
            ->getResult();
    }

    /**
     * Named fleets of alliance mates the player may see and steer.
     *
     * @return list<Fleet>
     */
    public function findControllableBy(User $player): array
    {
        if (null === $player->getAlliance()) {
            return [];
        }

        $candidates = $this->createQueryBuilder('f')
            ->join('f.owner', 'o')
            ->leftJoin('f.releasedTo', 'r')
            ->where('o.alliance = :alliance AND o <> :player AND f.name IS NOT NULL')
            ->andWhere('f.releasedToAlliance = true OR r = :player')
            ->setParameter('alliance', $player->getAlliance())
            ->setParameter('player', $player)
            ->orderBy('o.username', 'ASC')
            ->addOrderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();

        // The rank check (fleet rights for alliance-wide releases) lives in the entity.
        $unique = [];
        foreach ($candidates as $fleet) {
            if ($fleet->isControllableBy($player)) {
                $unique[$fleet->getId()] = $fleet;
            }
        }

        return array_values($unique);
    }

    /**
     * Foreign fleets heading towards one of the player's planets.
     *
     * @return list<Fleet>
     */
    public function findIncoming(User $player): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT f FROM App\Entity\Fleet f, App\Entity\Planet p
             WHERE p.owner = :player AND f.owner <> :player AND f.mission IS NOT NULL AND f.returning = false
               AND f.targetGalaxy = p.galaxy AND f.targetSystem = p.system
               AND f.targetPosition >= p.position AND f.targetPosition <= p.position + p.fusionSize - 1
             ORDER BY f.arriveAt ASC'
        )->setParameter('player', $player)->getResult();
    }
}
