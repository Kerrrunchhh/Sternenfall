<?php

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use App\Game\TicketKind;
use App\Game\TicketStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    /** @return list<Ticket> */
    public function findByAuthor(User $author): array
    {
        return $this->findBy(['author' => $author], ['updatedAt' => 'DESC']);
    }

    /** @return list<Ticket> */
    public function findForAdmins(?TicketKind $kind, bool $pendingOnly): array
    {
        $qb = $this->createQueryBuilder('t')->orderBy('t.updatedAt', 'DESC');
        if (null !== $kind) {
            $qb->andWhere('t.kind = :kind')->setParameter('kind', $kind);
        }
        if ($pendingOnly) {
            $qb->andWhere('t.status IN (:pending)')->setParameter('pending', [TicketStatus::Open, TicketStatus::Question]);
        }

        return $qb->setMaxResults(300)->getQuery()->getResult();
    }

    public function countOpen(): int
    {
        return $this->count(['status' => TicketStatus::Open]);
    }
}
