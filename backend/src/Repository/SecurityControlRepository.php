<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\SecurityControl;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SecurityControl> */
final class SecurityControlRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityControl::class);
    }

    /** @return list<SecurityControl> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['name' => 'ASC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?SecurityControl
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<SecurityControl>
     */
    public function findAllVisibleByIds(array $ids, User $actor): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('c')->andWhere('c.id IN (:ids)')->andWhere('c.organization = :organization')->setParameter('ids', $ids)->setParameter('organization', $actor->getOrganization())->getQuery()->getResult();
    }

    /** @return list<SecurityControl> */
    public function findForService(Organization $organization, int $limit, int $offset, ?string $status): array
    {
        return $this->serviceQuery($organization, $status)->orderBy('c.id', 'ASC')->setMaxResults($limit)->setFirstResult($offset)->getQuery()->getResult();
    }

    public function countForService(Organization $organization, ?string $status): int
    {
        return (int) $this->serviceQuery($organization, $status)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
    }

    private function serviceQuery(Organization $organization, ?string $status): \Doctrine\ORM\QueryBuilder
    {
        $query = $this->createQueryBuilder('c')->andWhere('c.organization = :organization')->setParameter('organization', $organization);
        if (null !== $status) {
            $query->andWhere('c.implementationStatus = :status')->setParameter('status', $status);
        }

        return $query;
    }
}
