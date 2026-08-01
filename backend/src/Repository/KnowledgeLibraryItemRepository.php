<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\KnowledgeLibraryItem;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<KnowledgeLibraryItem> */
final class KnowledgeLibraryItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KnowledgeLibraryItem::class);
    }

    /** @return list<KnowledgeLibraryItem> */
    public function search(Organization $organization, ?string $kind, ?string $status, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('item')->where('item.organization = :organization')->setParameter('organization', $organization)->orderBy('item.createdAt', 'DESC');
        if (null !== $kind) {
            $qb->andWhere('item.kind = :kind')->setParameter('kind', $kind);
        }
        if (null !== $status) {
            $qb->andWhere('item.status = :status')->setParameter('status', $status);
        }

        return $qb->setFirstResult((max(1, $page) - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();
    }

    public function findVisible(int $id, Organization $organization): ?KnowledgeLibraryItem
    {
        return $this->findOneBy(['id' => $id, 'organization' => $organization]);
    }

    public function countVisible(Organization $organization, ?string $kind = null, ?string $status = null): int
    {
        $criteria = ['organization' => $organization];
        if (null !== $kind) {
            $criteria['kind'] = $kind;
        }
        if (null !== $status) {
            $criteria['status'] = $status;
        }

        return $this->count($criteria);
    }

    public function hasApprovedDependency(Organization $organization, string $key, int $minVersion): bool
    {
        return null !== $this->createQueryBuilder('item')->select('item.id')->where('item.organization = :organization')->andWhere('item.key = :key')->andWhere('item.status = :status')->andWhere('item.version >= :version')->setParameter('organization', $organization)->setParameter('key', $key)->setParameter('status', 'APPROVED')->setParameter('version', $minVersion)->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
