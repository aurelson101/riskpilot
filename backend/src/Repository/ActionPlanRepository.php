<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActionPlan;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ActionPlan> */
final class ActionPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionPlan::class);
    }

    /** @return list<ActionPlan> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['dueDate' => 'ASC', 'priority' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?ActionPlan
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }

    /** @return list<ActionPlan> */
    public function findForRisk(int $riskId, User $actor): array
    {
        return $this->findBy(['relatedRisk' => $riskId, 'organization' => $actor->getOrganization()]);
    }
}
