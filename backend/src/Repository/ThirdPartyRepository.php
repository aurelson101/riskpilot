<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ThirdParty;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ThirdParty> */ final class ThirdPartyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThirdParty::class);
    }

    /** @return list<ThirdParty> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['criticality' => 'DESC', 'name' => 'ASC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?ThirdParty
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
