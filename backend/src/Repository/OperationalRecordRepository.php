<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OperationalRecord;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OperationalRecord> */
final class OperationalRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OperationalRecord::class);
    }

    /** @return list<OperationalRecord> */
    public function findForOrganization(Organization $organization, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('record')->where('record.organization = :organization')->setParameter('organization', $organization)->orderBy('record.updatedAt', 'DESC');
        if (null !== $type) {
            $qb->andWhere('record.type = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneVisible(int $id, Organization $organization): ?OperationalRecord
    {
        return $this->findOneBy(['id' => $id, 'organization' => $organization]);
    }

    /** @return list<OperationalRecord> */
    public function findOpenTasks(User $owner): array
    {
        return $this->createQueryBuilder('record')->where('record.organization = :organization')->andWhere('record.owner = :owner')->andWhere('record.type = :type')->andWhere('record.status NOT IN (:closed)')->setParameter('organization', $owner->getOrganization())->setParameter('owner', $owner)->setParameter('type', 'TASK')->setParameter('closed', ['COMPLETED', 'ARCHIVED'])->orderBy('record.dueAt', 'ASC')->getQuery()->getResult();
    }

    /** @return list<OperationalRecord> */
    public function findDueForReminder(): array
    {
        return $this->createQueryBuilder('record')->where('record.owner IS NOT NULL')->andWhere('record.dueAt IS NOT NULL')->andWhere('record.dueAt <= :horizon')->andWhere('record.status NOT IN (:closed)')->andWhere('record.lastReminderAt IS NULL OR record.lastReminderAt < :last')->setParameter('horizon', new \DateTimeImmutable('+7 days'))->setParameter('closed', ['COMPLETED', 'ARCHIVED'])->setParameter('last', new \DateTimeImmutable('-24 hours'))->getQuery()->getResult();
    }
}
