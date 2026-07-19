<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Framework;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Framework> */
final class FrameworkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Framework::class);
    }

    /** @return list<Framework> */
    public function findAvailable(): array
    {
        return $this->findBy([], ['name' => 'ASC', 'version' => 'DESC']);
    }
}
