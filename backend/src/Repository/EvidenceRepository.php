<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Evidence;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Evidence> */
final class EvidenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Evidence::class); }
    /** @return list<Evidence> */
    public function findVisibleTo(User $actor, int $page, int $limit, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('e')->andWhere('e.organization = :organization')->setParameter('organization', $actor->getOrganization())->orderBy('e.updatedAt', 'DESC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        if (null !== $status) $qb->andWhere('e.status = :status')->setParameter('status', $status);
        return $qb->getQuery()->getResult();
    }
    public function countVisibleTo(User $actor, ?string $status = null): int
    {
        $qb = $this->createQueryBuilder('e')->select('COUNT(e.id)')->andWhere('e.organization = :organization')->setParameter('organization', $actor->getOrganization());
        if (null !== $status) $qb->andWhere('e.status = :status')->setParameter('status', $status);
        return (int) $qb->getQuery()->getSingleScalarResult();
    }
    public function findOneVisibleTo(int $id, User $actor): ?Evidence { return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]); }
    /** @return list<Evidence> */
    public function findForRequirement(int $requirementId, User $actor): array { return $this->createQueryBuilder('e')->innerJoin('e.requirements', 'requirement')->andWhere('requirement.id = :requirement')->andWhere('e.organization = :organization')->setParameter('requirement', $requirementId)->setParameter('organization', $actor->getOrganization())->orderBy('e.updatedAt', 'DESC')->getQuery()->getResult(); }
}
