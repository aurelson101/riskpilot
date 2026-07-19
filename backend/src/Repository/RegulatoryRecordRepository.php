<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RegulatoryRecord;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RegulatoryRecord> */ final class RegulatoryRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegulatoryRecord::class);
    }

    /** @return list<RegulatoryRecord> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['updatedAt' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?RegulatoryRecord
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
