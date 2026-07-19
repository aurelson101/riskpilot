<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AuditLog;
use App\Entity\Organization;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class AuditLogTest extends TestCase
{
    public function testHashChainDetectsAnInvalidPredecessor(): void
    {
        $organization = new Organization('Test');
        $user = new User('admin@example.test', 'Ada', 'Admin', $organization);
        $first = new AuditLog($organization, $user, 'POST', 'risks', '1', ['title' => 'Risque'], '127.0.0.1', 'PHPUnit');
        $first->seal(null, 'request-1');
        $second = new AuditLog($organization, $user, 'PUT', 'risks', '1', ['title' => 'Risque révisé'], '127.0.0.1', 'PHPUnit');
        $second->seal($first->getEventHash(), 'request-2');

        self::assertTrue($first->verify(null));
        self::assertTrue($second->verify($first->getEventHash()));
        self::assertFalse($second->verify(str_repeat('0', 64)));
    }
}
