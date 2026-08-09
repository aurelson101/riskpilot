<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EbiosWorkshop;
use App\Entity\RiskAnalysis;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EbiosWorkshop> */
final class EbiosWorkshopRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EbiosWorkshop::class);
    }

    /** @return list<EbiosWorkshop> */
    public function forAnalysis(RiskAnalysis $analysis): array
    {
        return $this->findBy(['analysis' => $analysis], ['number' => 'ASC']);
    }
}
