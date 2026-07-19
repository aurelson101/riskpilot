<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditLog> */
final class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /** @return list<AuditLog> */
    public function findVisibleTo(User $actor): array
    {
        $query = $this->createQueryBuilder('a')->orderBy('a.createdAt', 'DESC')->addOrderBy('a.id', 'DESC')->setMaxResults(500);
        if (!in_array(User::ROLE_SUPER_ADMIN, $actor->getRoles(), true)) {
            $query->andWhere('a.organization = :organization')->setParameter('organization', $actor->getOrganization());
        }

        return $query->getQuery()->getResult();
    }

    public function latestHashFor(User $actor): ?string
    {
        $log = $this->findOneBy(['organization' => $actor->getOrganization()], ['createdAt' => 'DESC', 'id' => 'DESC']);

        return $log?->getEventHash();
    }

    /** @return list<AuditLog> */
    public function findSealedFor(User $actor): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.organization = :organization')
            ->andWhere('a.eventHash IS NOT NULL')
            ->setParameter('organization', $actor->getOrganization())
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()->getResult();
    }
}
