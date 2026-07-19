<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RiskReviewCampaign;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RiskReviewCampaign> */
final class RiskReviewCampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiskReviewCampaign::class);
    }

    /** @return list<RiskReviewCampaign> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['dueAt' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?RiskReviewCampaign
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }
}
