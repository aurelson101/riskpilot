<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RiskScenario;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RiskScenario> */
final class RiskScenarioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiskScenario::class);
    }

    /** @return list<RiskScenario> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['currentRiskScore' => 'DESC', 'title' => 'ASC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?RiskScenario
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
