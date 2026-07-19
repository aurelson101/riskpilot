<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditProgram;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditProgram> */
final class AuditProgramRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditProgram::class);
    }

    /** @return list<AuditProgram> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['year' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?AuditProgram
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
