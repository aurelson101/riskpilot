<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RiskGovernancePolicy;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RiskGovernancePolicy> */
final class RiskGovernancePolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiskGovernancePolicy::class);
    }

    /** @return list<RiskGovernancePolicy> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['domain' => 'ASC', 'family' => 'ASC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?RiskGovernancePolicy
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }

    public function findForRisk(User $actor, string $domain, string $family): ?RiskGovernancePolicy
    {
        return $this->findOneBy(['organization' => $actor->getOrganization(), 'domain' => $domain, 'family' => $family]) ?? $this->findOneBy(['organization' => $actor->getOrganization(), 'domain' => 'GLOBAL', 'family' => 'GLOBAL']);
    }
}
