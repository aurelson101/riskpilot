<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\NotificationService;
use App\Entity\Notification;
use App\Entity\NotificationOutbox;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\NotificationOutboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NotificationOutboxTest extends KernelTestCase
{
    public function testNotificationIsIdempotentAndClaimedAtomically(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $organization = new Organization('Outbox tenant');
        $recipient = new User('outbox@example.test', 'Outbox', 'Recipient', $organization, [User::ROLE_VIEWER]);
        $manager->persist($organization);
        $manager->persist($recipient);
        $manager->flush();

        $notifications = self::getContainer()->get(NotificationService::class);
        $notifications->notify($recipient, 'REMINDER', 'Échéance', 'Une action arrive à échéance.', '/actions', 'action-42-reminder');
        $manager->flush();
        $notifications->notify($recipient, 'REMINDER', 'Échéance', 'Une action arrive à échéance.', '/actions', 'action-42-reminder');
        $manager->flush();
        self::assertSame(1, $manager->getRepository(Notification::class)->count([]));
        self::assertSame(1, $manager->getRepository(NotificationOutbox::class)->count([]));

        $repository = self::getContainer()->get(NotificationOutboxRepository::class);
        $ids = $repository->claimDispatchableIds();
        self::assertCount(1, $ids);
        self::assertSame([], $repository->claimDispatchableIds());
        $manager->clear();
        $claimed = $manager->getRepository(NotificationOutbox::class)->find($ids[0]);
        self::assertInstanceOf(NotificationOutbox::class, $claimed);
        self::assertSame('DISPATCHED', $claimed->getStatus());
        self::assertSame(1, $claimed->getAttempts());
    }
}
