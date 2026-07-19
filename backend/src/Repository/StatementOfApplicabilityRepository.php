<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StatementOfApplicability;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StatementOfApplicability> */
final class StatementOfApplicabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StatementOfApplicability::class);
    }

    /** @return list<StatementOfApplicability> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['createdAt' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?StatementOfApplicability
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }

    public function nextVersion(StatementOfApplicability $statement): int
    {
        return (int) $this->createQueryBuilder('s')->select('MAX(s.versionNumber)')->where('s.organization = :organization')->andWhere('s.framework = :framework')->andWhere('s.scope = :scope')->setParameter('organization', $statement->getOrganization())->setParameter('framework', $statement->getFramework())->setParameter('scope', $statement->getScope())->getQuery()->getSingleScalarResult() + 1;
    }
}
