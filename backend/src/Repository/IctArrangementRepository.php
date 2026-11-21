<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IctArrangement;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<IctArrangement> */
final class IctArrangementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IctArrangement::class);
    }

    /** @return list<IctArrangement> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['id' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?IctArrangement
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
