<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\NotificationOutbox;
use App\Repository\NotificationOutboxRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class NotificationOutboxRepositoryTest extends TestCase
{
    public function testClaimBoundsRetriesAndRetainsAtomicReservation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::once())->method('fetchFirstColumn')->willReturnCallback(
            static function (string $sql, array $parameters): array {
                self::assertStringContainsString('attempts < :maximum', $sql);
                self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $sql);
                self::assertSame(5, $parameters['maximum']);
                return ['42'];
            },
        );
        $calls = 0;
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters) use (&$calls): int {
                if (0 === $calls++) {
                    self::assertStringContainsString('attempts >= :maximum', $sql);
                    self::assertSame('DEAD_LETTER', $parameters['terminal']);
                    self::assertSame(5, $parameters['maximum']);
                } else {
                    self::assertSame('DISPATCHED', $parameters['status']);
                    self::assertSame([42], $parameters['ids']);
                }
                return 1;
            },
        );
        self::assertSame([42], $this->repository($connection)->claimDispatchableIds());
    }

    public function testClaimRollsBackOnDatabaseFailure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('Simulated database failure'));
        $this->expectException(\RuntimeException::class);
        $this->repository($connection)->claimDispatchableIds();
    }

    private function repository(Connection $connection): NotificationOutboxRepository
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getClassMetadata')->willReturn(new ClassMetadata(NotificationOutbox::class));
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        return new NotificationOutboxRepository($registry);
    }
}
