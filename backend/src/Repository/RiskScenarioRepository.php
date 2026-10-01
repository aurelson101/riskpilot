<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
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

    /** @return list<RiskScenario> */
    public function findForService(Organization $organization, int $limit, int $offset, ?string $status, ?\DateTimeImmutable $updatedSince): array
    {
        return $this->serviceQuery($organization, $status, $updatedSince)->orderBy('r.updatedAt', 'ASC')->addOrderBy('r.id', 'ASC')->setMaxResults($limit)->setFirstResult($offset)->getQuery()->getResult();
    }

    public function countForService(Organization $organization, ?string $status, ?\DateTimeImmutable $updatedSince): int
    {
        return (int) $this->serviceQuery($organization, $status, $updatedSince)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
    }

    private function serviceQuery(Organization $organization, ?string $status, ?\DateTimeImmutable $updatedSince): \Doctrine\ORM\QueryBuilder
    {
        $query = $this->createQueryBuilder('r')->andWhere('r.organization = :organization')->setParameter('organization', $organization);
        if (null !== $status) {
            $query->andWhere('r.status = :status')->setParameter('status', $status);
        }
        if (null !== $updatedSince) {
            $query->andWhere('r.updatedAt >= :updatedSince')->setParameter('updatedSince', $updatedSince);
        }

        return $query;
    }
}
