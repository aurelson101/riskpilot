<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComplianceAssessment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ComplianceAssessment> */
final class ComplianceAssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceAssessment::class);
    }

    /** @return list<ComplianceAssessment> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['assessmentDate' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?ComplianceAssessment
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
