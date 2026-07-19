<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComplianceAssessment;
use App\Entity\ComplianceResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ComplianceResult> */
final class ComplianceResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceResult::class);
    }

    /** @return list<ComplianceResult> */
    public function findForAssessment(ComplianceAssessment $assessment): array
    {
        return $this->findBy(['assessment' => $assessment], ['id' => 'ASC']);
    }
}
