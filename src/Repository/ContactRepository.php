<?php

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /** The contact or request between two players, in either direction. */
    public function findBetween(User $a, User $b): ?Contact
    {
        return $this->createQueryBuilder('c')
            ->where('(c.requester = :a AND c.addressee = :b) OR (c.requester = :b AND c.addressee = :a)')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Contact> accepted contacts of the player */
    public function findAccepted(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('r', 'a')
            ->join('c.requester', 'r')
            ->join('c.addressee', 'a')
            ->where('(c.requester = :u OR c.addressee = :u) AND c.acceptedAt IS NOT NULL')
            ->setParameter('u', $user)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Contact> requests waiting for the player's answer */
    public function findIncomingRequests(User $user): array
    {
        return $this->findBy(['addressee' => $user, 'acceptedAt' => null], ['createdAt' => 'ASC']);
    }

    /** @return list<Contact> the player's own requests not answered yet */
    public function findOutgoingRequests(User $user): array
    {
        return $this->findBy(['requester' => $user, 'acceptedAt' => null], ['createdAt' => 'ASC']);
    }

    public function countIncomingRequests(User $user): int
    {
        return $this->count(['addressee' => $user, 'acceptedAt' => null]);
    }
}
