<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiImpactAssessment;
use App\Entity\AiSystem;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AiImpactAssessment> */
final class AiImpactAssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiImpactAssessment::class);
    }

    /** @return list<AiImpactAssessment> */
    public function findForSystem(AiSystem $system): array
    {
        return $this->findBy(['system' => $system], ['id' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?AiImpactAssessment
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }

    public function hasApprovedFor(AiSystem $system): bool
    {
        return null !== $this->findOneBy(['system' => $system, 'status' => 'APPROVED']);
    }
}
