<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Application\EmailTemplateRenderer;
use App\Application\OrganizationMailer;
use App\Entity\Notification;
use App\Entity\NotificationOutbox;
use App\Entity\Organization;
use App\Entity\User;
use App\Message\DispatchNotificationOutbox;
use App\MessageHandler\DispatchNotificationOutboxHandler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class DispatchNotificationOutboxHandlerTest extends TestCase
{
    public function testIneligibleRecipientsAreCancelledBeforeCallingTheMailer(): void
    {
        foreach (['inactive', 'locked', 'other-tenant'] as $case) {
            $item = $this->item();
            if ('other-tenant' === $case) {
                $item->getRecipient()->setOrganization(new Organization('Other tenant'));
            } else {
                $item->getRecipient()->setStatus('locked' === $case ? User::STATUS_LOCKED : User::STATUS_INACTIVE);
            }
            ($this->handler($item))(new DispatchNotificationOutbox(42));
            self::assertSame('CANCELLED', $item->getStatus());
            self::assertSame('RECIPIENT_NO_LONGER_ELIGIBLE', $item->getLastError());
        }
    }

    public function testTransportExceptionIsNotCopiedToTheOutbox(): void
    {
        $item = $this->item();
        ($this->handler($item))(new DispatchNotificationOutbox(42));
        self::assertSame('FAILED', $item->getStatus());
        self::assertSame('MAIL_SEND_FAILED', $item->getLastError());
    }

    private function handler(NotificationOutbox $item): DispatchNotificationOutboxHandler
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($item);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->expects(self::once())->method('flush');
        // Deliberately uninitialized: any send raises an Error, without a transport or network.
        // Cancellation must happen before that call; normal failures must store only a safe code.
        $mailer = (new \ReflectionClass(OrganizationMailer::class))->newInstanceWithoutConstructor();
        return new DispatchNotificationOutboxHandler($manager, $mailer, new EmailTemplateRenderer('https://riskpilot.example'));
    }

    private function item(): NotificationOutbox
    {
        $organization = new Organization('Original tenant');
        (new \ReflectionProperty(Organization::class, 'id'))->setValue($organization, 1);
        $recipient = new User('recipient@example.test', 'Test', 'Recipient', $organization);
        $item = new NotificationOutbox(new Notification($recipient, 'REMINDER', 'Title', 'Body'), $recipient, 'test-key', ['subject' => 'Title', 'message' => 'Body']);
        $item->markDispatched();
        return $item;
    }
}
