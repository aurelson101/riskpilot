<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Notification;
use App\Entity\NotificationOutbox;
use App\Entity\Organization;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class NotificationOutboxStateTest extends TestCase
{
    public function testFailuresStopAfterFiveAttempts(): void
    {
        $item = $this->item();
        for ($attempt = 1; $attempt <= NotificationOutbox::MAX_ATTEMPTS; ++$attempt) {
            $item->markDispatched();
            self::assertSame($attempt, $item->getAttempts());
            $item->markFailed();
            self::assertSame($attempt < NotificationOutbox::MAX_ATTEMPTS ? 'FAILED' : 'DEAD_LETTER', $item->getStatus());
            self::assertSame('MAIL_SEND_FAILED', $item->getLastError());
        }
        $item->markDispatched();
        $item->markSent();
        self::assertSame('DEAD_LETTER', $item->getStatus());
        self::assertSame(5, $item->getAttempts());
    }

    public function testFirstFailureIsDelayedByTwoMinutes(): void
    {
        $item = $this->item();
        $now = time();
        $item->markDispatched();
        $item->markFailed();
        self::assertGreaterThanOrEqual($now + 120, $item->getAvailableAt()->getTimestamp());
        self::assertLessThanOrEqual(time() + 120, $item->getAvailableAt()->getTimestamp());
    }

    public function testInactiveAndLockedRecipientsAreNotEligible(): void
    {
        $item = $this->item();
        self::assertTrue($item->isRecipientEligible());
        foreach ([User::STATUS_INACTIVE, User::STATUS_LOCKED] as $status) {
            $item->getRecipient()->setStatus($status);
            self::assertFalse($item->isRecipientEligible());
        }
    }

    public function testMovingRecipientToAnotherTenantMakesItIneligible(): void
    {
        $item = $this->item();
        $original = $item->getOrganization();
        $item->getRecipient()->setOrganization(new Organization('Other tenant'));
        self::assertFalse($item->isRecipientEligible());
        self::assertSame($original, $item->getOrganization());
    }

    public function testCancelledAndSentMessagesCannotBeRetried(): void
    {
        foreach (['CANCELLED', 'SENT'] as $terminal) {
            $item = $this->item();
            $item->markDispatched();
            'SENT' === $terminal ? $item->markSent() : $item->markCancelled();
            $item->markFailed();
            $item->markDispatched();
            $item->markCancelled();
            self::assertSame($terminal, $item->getStatus());
            self::assertSame(1, $item->getAttempts());
        }
    }

    private function item(): NotificationOutbox
    {
        $recipient = new User('recipient@example.test', 'Test', 'Recipient', new Organization('Original tenant'));
        return new NotificationOutbox(new Notification($recipient, 'REMINDER', 'Title', 'Body'), $recipient, 'unique-key', ['subject' => 'Title', 'message' => 'Body']);
    }
}
