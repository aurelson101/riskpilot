<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExecutiveGovernanceRecord;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ExecutiveGovernanceRecord> */ final class ExecutiveGovernanceRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExecutiveGovernanceRecord::class);
    }

    /** @return list<ExecutiveGovernanceRecord> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['updatedAt' => 'DESC']);
    }
}
