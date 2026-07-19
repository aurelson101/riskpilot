<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\PlatformIntegration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PlatformIntegration> */
final class PlatformIntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlatformIntegration::class);
    }

    /** @return list<PlatformIntegration> */
    public function findForOrganization(Organization $organization): array
    {
        return $this->findBy(['organization' => $organization], ['type' => 'ASC', 'name' => 'ASC']);
    }

    public function findApiKey(string $prefix): ?PlatformIntegration
    {
        return $this->findOneBy(['type' => 'API_KEY', 'credentialPrefix' => $prefix, 'enabled' => true]);
    }
}
