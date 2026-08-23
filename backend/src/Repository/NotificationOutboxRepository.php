<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationOutbox;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NotificationOutbox> */
final class NotificationOutboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, NotificationOutbox::class); }
    public function existsForKey(Organization $organization, string $idempotencyKey): bool
    {
        return null !== $this->findOneBy([
            'organization' => $organization,
            'idempotencyKey' => hash('sha256', $idempotencyKey),
        ]);
    }
    /** @return list<int> */
    public function claimDispatchableIds(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $connection = $this->getEntityManager()->getConnection();
        $connection->beginTransaction();
        try {
            $ids = array_map('intval', $connection->fetchFirstColumn(
                'SELECT id FROM notification_outbox WHERE status IN (:pending, :failed) AND available_at <= :now ORDER BY created_at ASC LIMIT '.$limit.' FOR UPDATE SKIP LOCKED',
                ['pending' => 'PENDING', 'failed' => 'FAILED', 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            ));
            if ([] !== $ids) {
                $connection->executeStatement('UPDATE notification_outbox SET status = :status, attempts = attempts + 1 WHERE id IN (:ids)', ['status' => 'DISPATCHED', 'ids' => $ids], ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]);
            }
            $connection->commit();

            return $ids;
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }
}
