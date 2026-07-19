<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Framework;
use App\Entity\Requirement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Requirement> */
final class RequirementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Requirement::class);
    }

    /** @return list<Requirement> */
    public function findForFramework(Framework $framework): array
    {
        return $this->findBy(['framework' => $framework], ['category' => 'ASC', 'reference' => 'ASC']);
    }
}
