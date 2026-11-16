<?php

namespace App\Repository;

use App\Entity\Poll;
use App\Entity\PollVote;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Poll> */
class PollRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Poll::class);
    }

    /** @return list<Poll> newest first */
    public function findLatest(int $limit = 30): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    /** @return list<PollVote> */
    public function votesOf(Poll $poll): array
    {
        return $this->getEntityManager()->getRepository(PollVote::class)->findBy(['poll' => $poll], ['createdAt' => 'ASC']);
    }

    public function voteOf(Poll $poll, User $user): ?PollVote
    {
        return $this->getEntityManager()->getRepository(PollVote::class)->findOneBy(['poll' => $poll, 'user' => $user]);
    }

    /** Running polls the player has not answered yet (the badge in the menu). */
    public function countOpenFor(User $user, \DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'SELECT COUNT(p.id) FROM '.Poll::class.' p WHERE p.endsAt > :now AND p.createdAt <= :now
             AND NOT EXISTS (SELECT v.id FROM '.PollVote::class.' v WHERE v.poll = p AND v.user = :user)',
        )->setParameter('now', $now)->setParameter('user', $user)->getSingleScalarResult();
    }
}
