<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\AuditLog;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;

/** Ajoute un événement à la chaîne d'audit scellée de l'organisation, sous verrou consultatif pour garder l'ordre. */
final readonly class AuditTrail
{
    public function __construct(private EntityManagerInterface $entityManager, private AuditLogRepository $logs)
    {
    }

    /** @param array<string, mixed>|null $newValues */
    public function record(Organization $organization, ?User $actor, string $action, string $entityType, ?string $entityId, ?array $newValues, ?string $ip, ?string $userAgent, string $requestId): void
    {
        $log = new AuditLog($organization, $actor, $action, $entityType, $entityId, $newValues, $ip, $userAgent);
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $connection->executeQuery('SELECT pg_advisory_xact_lock(:organization)', ['organization' => (int) $organization->getId()]);
            }
            $log->seal($this->logs->latestHashForOrganization($organization), mb_substr($requestId, 0, 36));
            $this->entityManager->persist($log);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }
}
