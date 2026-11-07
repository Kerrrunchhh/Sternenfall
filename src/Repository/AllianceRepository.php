<?php

namespace App\Repository;

use App\Entity\Alliance;
use App\Entity\AlliancePost;
use App\Entity\AllianceRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Alliance>
 */
class AllianceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Alliance::class);
    }

    /** @return list<Alliance> */
    public function findAllByName(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /** @return list<AllianceRequest> invitations a player received */
    public function findInvitationsFor(User $user): array
    {
        return $this->getEntityManager()->getRepository(AllianceRequest::class)->findBy(['user' => $user, 'invitation' => true], ['createdAt' => 'DESC']);
    }

    /** @return list<AllianceRequest> open invitations and applications of an alliance */
    public function findRequests(Alliance $alliance): array
    {
        return $this->getEntityManager()->getRepository(AllianceRequest::class)->findBy(['alliance' => $alliance], ['createdAt' => 'DESC']);
    }

    public function findRequest(Alliance $alliance, User $user): ?AllianceRequest
    {
        return $this->getEntityManager()->getRepository(AllianceRequest::class)->findOneBy(['alliance' => $alliance, 'user' => $user]);
    }

    /** @return list<AlliancePost> */
    public function findPosts(Alliance $alliance, int $limit = 50): array
    {
        return $this->getEntityManager()->getRepository(AlliancePost::class)->findBy(['alliance' => $alliance], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
