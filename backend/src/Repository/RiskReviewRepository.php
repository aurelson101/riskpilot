<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RiskReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RiskReview> */
final class RiskReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiskReview::class);
    }

    /** @return list<RiskReview> */
    public function findDueForReminder(): array
    {
        return $this->createQueryBuilder('review')->join('review.campaign', 'campaign')->where('review.status != :completed')->andWhere('campaign.status = :active')->andWhere('campaign.dueAt <= :horizon')->andWhere('review.lastReminderAt IS NULL OR review.lastReminderAt < :lastReminder')->setParameter('completed', 'COMPLETED')->setParameter('active', 'ACTIVE')->setParameter('horizon', new \DateTimeImmutable('+7 days'))->setParameter('lastReminder', new \DateTimeImmutable('-24 hours'))->getQuery()->getResult();
    }
}
