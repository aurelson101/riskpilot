<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RequirementMapping;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RequirementMapping> */
final class RequirementMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RequirementMapping::class);
    }

    /** @return list<RequirementMapping> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['createdAt' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?RequirementMapping
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
