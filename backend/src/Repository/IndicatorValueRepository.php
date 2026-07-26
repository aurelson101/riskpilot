<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndicatorValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<IndicatorValue> */
final class IndicatorValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicatorValue::class);
    }
}
