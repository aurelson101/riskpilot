<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActionPlan;
use App\Entity\Organization;
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

    /** @return list<ActionPlan> */
    public function findForService(Organization $organization, int $limit, int $offset, ?string $status, ?\DateTimeImmutable $updatedSince): array
    {
        return $this->serviceQuery($organization, $status, $updatedSince)->orderBy('a.updatedAt', 'ASC')->addOrderBy('a.id', 'ASC')->setMaxResults($limit)->setFirstResult($offset)->getQuery()->getResult();
    }

    public function countForService(Organization $organization, ?string $status, ?\DateTimeImmutable $updatedSince): int
    {
        return (int) $this->serviceQuery($organization, $status, $updatedSince)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
    }

    private function serviceQuery(Organization $organization, ?string $status, ?\DateTimeImmutable $updatedSince): \Doctrine\ORM\QueryBuilder
    {
        $query = $this->createQueryBuilder('a')->andWhere('a.organization = :organization')->setParameter('organization', $organization);
        if (null !== $status) {
            $query->andWhere('a.status = :status')->setParameter('status', $status);
        }
        if (null !== $updatedSince) {
            $query->andWhere('a.updatedAt >= :updatedSince')->setParameter('updatedSince', $updatedSince);
        }

        return $query;
    }
}
