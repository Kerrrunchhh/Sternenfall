<?php

namespace App\Repository;

use App\Entity\TransportRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransportRequest>
 */
class TransportRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransportRequest::class);
    }

    /** @return list<TransportRequest> */
    public function findOpen(): array
    {
        return $this->findBy(['status' => TransportRequest::OPEN], ['createdAt' => 'ASC']);
    }

    /** @return list<TransportRequest> */
    public function findOf(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.requester = :u OR r.trader = :u')->setParameter('u', $user)
            ->orderBy('r.createdAt', 'DESC')->setMaxResults(100)->getQuery()->getResult();
    }

    /** @return list<TransportRequest> requests the player asked for or delivers that are still running (open or on their way) */
    public function findRunningOf(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->where('(r.requester = :u OR r.trader = :u) AND r.status IN (:running)')
            ->setParameter('u', $user)
            ->setParameter('running', [TransportRequest::OPEN, TransportRequest::ASSIGNED])
            ->orderBy('r.createdAt', 'ASC')->getQuery()->getResult();
    }

    /** Open requests of other players – what a trader could deliver. */
    public function countOpenForOthers(User $user): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.status = :open AND r.requester <> :u')
            ->setParameter('open', TransportRequest::OPEN)
            ->setParameter('u', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Requests of a player still running: open or on their way. */
    public function countActive(User $user): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.requester = :user AND r.status IN (:running)')
            ->setParameter('user', $user)
            ->setParameter('running', [TransportRequest::OPEN, TransportRequest::ASSIGNED])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Deletes delivered and cancelled requests closed before $before. @return int how many */
    public function deleteClosedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.status IN (:closed) AND r.closedAt < :before')
            ->setParameter('closed', [TransportRequest::DONE, TransportRequest::CANCELLED])
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /** A planet was renamed: entries for its coordinates show the new name (a copy is kept for when the planet is gone). */
    public function renamePlanetAt(int $galaxy, int $system, int $position, string $name): void
    {
        $this->getEntityManager()->createQuery('UPDATE '.TransportRequest::class.' e SET e.planetName = :name WHERE e.galaxy = :galaxy AND e.system = :system AND e.position = :position')
            ->setParameter('name', mb_substr($name, 0, 30))
            ->setParameter('galaxy', $galaxy)
            ->setParameter('system', $system)
            ->setParameter('position', $position)
            ->execute();
    }
}
