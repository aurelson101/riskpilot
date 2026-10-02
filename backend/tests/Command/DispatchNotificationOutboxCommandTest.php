<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Application\NotificationService;
use App\Command\DispatchNotificationOutboxCommand;
use App\Entity\Organization;
use App\Entity\User;
use App\Entity\NotificationOutbox;
use App\Repository\NotificationOutboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class DispatchNotificationOutboxCommandTest extends KernelTestCase
{
    public function testBrokerFailureReleasesOnlyUnpublishedEntriesAndReportsFailure(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $org = new Organization('Outbox publisher');
        $user = new User('publisher@example.test', 'Pub', 'User', $org);
        $em->persist($org);
        $em->persist($user);
        $em->flush();
        $service = self::getContainer()->get(NotificationService::class);
        for ($index = 0; $index < 3; ++$index) {
            $service->notify($user, 'REMINDER', 'Reminder '.$index, 'Body', '/actions', 'publisher-'.$index);
        }
        $em->flush();
        $calls = 0;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(static function (object $message) use (&$calls): Envelope {
            if (1 === $calls++) {
                throw new \RuntimeException('Internal transport secret must not be exposed');
            }
            return new Envelope($message);
        });
        $repository = self::getContainer()->get(NotificationOutboxRepository::class);
        $tester = new CommandTester(new DispatchNotificationOutboxCommand($repository, $bus));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('1 message(s) published', $tester->getDisplay());
        self::assertStringNotContainsString('Internal transport secret', $tester->getDisplay());
        $em->clear();
        $entries = $em->getRepository(NotificationOutbox::class)->findBy([], ['id' => 'ASC']);
        self::assertSame('DISPATCHED', $entries[0]->getStatus());
        foreach (array_slice($entries, 1) as $entry) {
            self::assertSame('FAILED', $entry->getStatus());
            self::assertSame(0, $entry->getAttempts());
            self::assertSame('OUTBOX_PUBLISH_FAILED', $entry->getLastError());
            self::assertGreaterThan(time(), $entry->getAvailableAt()->getTimestamp());
        }
        self::assertSame([], $repository->claimDispatchableIds());
    }
}
