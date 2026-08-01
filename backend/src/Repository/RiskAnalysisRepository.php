<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\RiskAnalysis;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RiskAnalysis> */ final class RiskAnalysisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $r)
    {
        parent::__construct($r, RiskAnalysis::class);
    }

    public function visible(int $id, Organization $o): ?RiskAnalysis
    {
        return $this->findOneBy(['id' => $id, 'organization' => $o]);
    }

    /** @return list<RiskAnalysis> */
    public function search(Organization $o, int $page, int $limit): array
    {
        return $this->findBy(['organization' => $o], ['createdAt' => 'DESC'], min(100, max(1, $limit)), (max(1, $page) - 1) * $limit);
    }
}
