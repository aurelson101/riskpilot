<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Asset;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Asset> */
final class AssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Asset::class);
    }

    /** @return list<Asset> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['name' => 'ASC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?Asset
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Asset>
     */
    public function findAllVisibleByIds(array $ids, User $actor): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('a')->andWhere('a.id IN (:ids)')->andWhere('a.organization = :organization')->setParameter('ids', $ids)->setParameter('organization', $actor->getOrganization())->getQuery()->getResult();
    }
}
