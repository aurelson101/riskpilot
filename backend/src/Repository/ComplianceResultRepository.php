<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComplianceAssessment;
use App\Entity\ComplianceResult;
use App\Entity\User;
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

    /** @return list<ComplianceResult> */
    public function findActionableVisibleTo(User $actor, int $limit = 200): array
    {
        return $this->createQueryBuilder('result')
            ->innerJoin('result.assessment', 'assessment')
            ->innerJoin('result.requirement', 'requirement')
            ->andWhere('assessment.organization = :organization')
            ->andWhere('result.complianceStatus IN (:statuses)')
            ->setParameter('organization', $actor->getOrganization())
            ->setParameter('statuses', ['PARTIAL', 'NON_COMPLIANT', 'NOT_ASSESSED'])
            ->orderBy('result.id', 'DESC')
            ->setMaxResults(max(1, min(200, $limit)))
            ->getQuery()
            ->getResult();
    }
}
