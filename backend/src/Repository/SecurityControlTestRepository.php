<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SecurityControlTest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SecurityControlTest> */
final class SecurityControlTestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityControlTest::class);
    }

    /** @return list<SecurityControlTest> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['nextReviewAt' => 'ASC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?SecurityControlTest
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
