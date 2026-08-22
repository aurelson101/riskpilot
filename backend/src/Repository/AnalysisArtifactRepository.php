<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnalysisArtifact;
use App\Entity\Organization;
use App\Entity\RiskAnalysis;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AnalysisArtifact> */ final class AnalysisArtifactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $r)
    {
        parent::__construct($r, AnalysisArtifact::class);
    }

    /** @return list<AnalysisArtifact> */
    public function search(Organization $o, ?RiskAnalysis $a, ?string $kind, int $page, int $limit): array
    {
        $c = ['organization' => $o];
        if (null !== $a) {
            $c['analysis'] = $a;
        }if (null !== $kind) {
            $c['kind'] = $kind;
        }

        return $this->findBy($c, ['createdAt' => 'DESC'], min(100, max(1, $limit)), (max(1, $page) - 1) * $limit);
    }
}
