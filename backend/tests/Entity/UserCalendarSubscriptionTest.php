<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Organization;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserCalendarSubscriptionTest extends TestCase
{
    public function testCalendarSubscriptionCanBeEnabledRotatedAndRevoked(): void
    {
        $user = new User('owner@example.test', 'Action', 'Owner', new Organization('Test'));

        self::assertNull($user->getCalendarTokenHash());
        self::assertNull($user->getCalendarTokenCreatedAt());

        $user->enableCalendarSubscription(hash('sha256', 'first-token'));
        self::assertSame(hash('sha256', 'first-token'), $user->getCalendarTokenHash());
        self::assertNotNull($user->getCalendarTokenCreatedAt());

        $user->enableCalendarSubscription(hash('sha256', 'rotated-token'));
        self::assertSame(hash('sha256', 'rotated-token'), $user->getCalendarTokenHash());

        $user->disableCalendarSubscription();
        self::assertNull($user->getCalendarTokenHash());
        self::assertNull($user->getCalendarTokenCreatedAt());
    }
}
