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

        // A legacy failure already at the cap must be terminalized, never reserved again.
        $manager->getConnection()->executeStatement(
            'UPDATE notification_outbox SET status = :status, attempts = :attempts WHERE id = :id',
            ['status' => 'FAILED', 'attempts' => NotificationOutbox::MAX_ATTEMPTS, 'id' => $claimed->getId()],
        );
        self::assertSame([], $repository->claimDispatchableIds());
        $manager->clear();
        self::assertSame('DEAD_LETTER', $manager->getRepository(NotificationOutbox::class)->find($ids[0])->getStatus());

        $organization = $manager->getRepository(Organization::class)->find($organization->getId());
        $english = (new User('english@example.test', 'English', 'Recipient', $organization, [User::ROLE_VIEWER]))->setLocale('en');
        $manager->persist($english);
        $manager->flush();
        $due = new \DateTimeImmutable('2030-01-02');
        $notifications->notifyLocalized($english, 'ACTION_ASSIGNED', ['First action', $due], '/actions');
        $manager->flush();
        $notifications->notifyLocalized($english, 'ACTION_ASSIGNED', ['Second action', $due], '/actions');
        $manager->flush();
        $english->setLocale('fr');
        $notifications->notifyLocalized($english, 'ACTION_ASSIGNED', ['First action', $due], '/actions');
        $manager->flush();
        self::assertSame(3, $manager->getRepository(Notification::class)->count([]));
        $notification = $manager->getRepository(Notification::class)->findOneBy(['recipient' => $english]);
        self::assertSame('New action assigned', $notification->getTitle());
        self::assertStringContainsString('2030-01-02', $notification->getMessage());
        $english->setStatus(User::STATUS_INACTIVE);
        $manager->flush();
        self::assertSame([], $repository->claimDispatchableIds());
        self::assertSame(2, $manager->getRepository(NotificationOutbox::class)->count(['status' => 'CANCELLED']));
    }
}
