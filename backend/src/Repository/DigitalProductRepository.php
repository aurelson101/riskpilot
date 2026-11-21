<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DigitalProduct;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DigitalProduct> */
final class DigitalProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DigitalProduct::class);
    }

    /** @return list<DigitalProduct> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['id' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?DigitalProduct
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
