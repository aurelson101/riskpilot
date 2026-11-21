<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RegulatoryIncident;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RegulatoryIncident> */
final class RegulatoryIncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegulatoryIncident::class);
    }

    /** @return list<RegulatoryIncident> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['id' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?RegulatoryIncident
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
