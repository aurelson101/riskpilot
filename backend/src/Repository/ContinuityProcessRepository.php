<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContinuityProcess;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContinuityProcess> */ final class ContinuityProcessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContinuityProcess::class);
    }

    /** @return list<ContinuityProcess> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['criticality' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?ContinuityProcess
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
