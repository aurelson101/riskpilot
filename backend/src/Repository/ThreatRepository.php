<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Threat;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Threat> */
final class ThreatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Threat::class);
    }

    /** @return list<Threat> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['name' => 'ASC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?Threat
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
