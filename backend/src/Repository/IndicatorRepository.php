<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Indicator;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Indicator> */
final class IndicatorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Indicator::class);
    }

    /** @return list<Indicator> */
    public function findForOrganization(Organization $organization): array
    {
        return $this->findBy(['organization' => $organization], ['name' => 'ASC']);
    }

    public function findOneForOrganization(int $id, Organization $organization): ?Indicator
    {
        return $this->findOneBy(['id' => $id, 'organization' => $organization]);
    }
}
