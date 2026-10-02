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
            $connection->executeStatement(
                'UPDATE notification_outbox o SET status = :cancelled, last_error = :error WHERE o.status IN (:pending, :failed) AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = o.recipient_id AND u.organization_id = o.organization_id AND u.status = :active)',
                ['cancelled' => 'CANCELLED', 'error' => 'RECIPIENT_NO_LONGER_ELIGIBLE', 'pending' => 'PENDING', 'failed' => 'FAILED', 'active' => 'ACTIVE'],
            );
            $connection->executeStatement(
                'UPDATE notification_outbox SET status = :terminal WHERE status IN (:pending, :failed) AND attempts >= :maximum',
                ['terminal' => 'DEAD_LETTER', 'pending' => 'PENDING', 'failed' => 'FAILED', 'maximum' => NotificationOutbox::MAX_ATTEMPTS],
            );
            $ids = array_map('intval', $connection->fetchFirstColumn(
                'SELECT id FROM notification_outbox WHERE status IN (:pending, :failed) AND attempts < :maximum AND available_at <= :now ORDER BY created_at ASC, id ASC LIMIT '.$limit.' FOR UPDATE SKIP LOCKED',
                ['pending' => 'PENDING', 'failed' => 'FAILED', 'maximum' => NotificationOutbox::MAX_ATTEMPTS, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
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

    /** @param list<int> $ids */
    public function releaseUnpublishedIds(array $ids): void
    {
        if ([] === $ids) return;
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE notification_outbox SET status = :failed, attempts = GREATEST(0, attempts - 1), available_at = :available, last_error = :error WHERE status = :dispatched AND id IN (:ids)',
            ['failed' => 'FAILED', 'dispatched' => 'DISPATCHED', 'available' => (new \DateTimeImmutable('+2 minutes'))->format('Y-m-d H:i:s'), 'error' => 'OUTBOX_PUBLISH_FAILED', 'ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }
}
