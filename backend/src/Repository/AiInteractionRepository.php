<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiInteraction;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AiInteraction> */
final class AiInteractionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiInteraction::class);
    }

    /** @return array{calls: int, succeeded: int, failed: int, refused: int, inputTokens: int, outputTokens: int} */
    public function usageSince(Organization $organization, \DateTimeImmutable $since): array
    {
        $row = $this->createQueryBuilder('interaction')
            ->select('COUNT(interaction.id) AS calls')
            ->addSelect("SUM(CASE WHEN interaction.status = 'OK' THEN 1 ELSE 0 END) AS succeeded")
            ->addSelect("SUM(CASE WHEN interaction.status = 'ERROR' THEN 1 ELSE 0 END) AS failed")
            ->addSelect("SUM(CASE WHEN interaction.status IN ('RATE_LIMITED', 'BUDGET_EXCEEDED') THEN 1 ELSE 0 END) AS refused")
            ->addSelect('COALESCE(SUM(interaction.inputTokens), 0) AS inputTokens')
            ->addSelect('COALESCE(SUM(interaction.outputTokens), 0) AS outputTokens')
            ->where('interaction.organization = :organization')
            ->andWhere('interaction.createdAt >= :since')
            ->setParameter('organization', $organization)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        return [
            'calls' => (int) $row['calls'],
            'succeeded' => (int) $row['succeeded'],
            'failed' => (int) $row['failed'],
            'refused' => (int) $row['refused'],
            'inputTokens' => (int) $row['inputTokens'],
            'outputTokens' => (int) $row['outputTokens'],
        ];
    }

    /** Appels réellement transmis au fournisseur (les refus locaux ne consomment pas le plafond). */
    public function providerCallsSince(Organization $organization, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('interaction')
            ->select('COUNT(interaction.id)')
            ->where('interaction.organization = :organization')
            ->andWhere('interaction.createdAt >= :since')
            ->andWhere("interaction.status IN ('OK', 'ERROR')")
            ->setParameter('organization', $organization)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
