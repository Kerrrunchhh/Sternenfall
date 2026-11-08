<?php

namespace App\Repository;

use App\Entity\FusionatorOffer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FusionatorOffer> */
class FusionatorOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FusionatorOffer::class);
    }

    /** @return list<FusionatorOffer> cheapest first */
    public function findAllByPrice(): array
    {
        return $this->findBy([], ['price' => 'ASC', 'createdAt' => 'ASC']);
    }
}
