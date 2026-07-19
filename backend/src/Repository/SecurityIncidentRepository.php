<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SecurityIncident;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SecurityIncident> */ final class SecurityIncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityIncident::class);
    }

    /** @return list<SecurityIncident> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['detectedAt' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?SecurityIncident
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
