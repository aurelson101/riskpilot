<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\DirectoryConnectionTester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DirectoryConnectionTesterTest extends TestCase
{
    public function testUniqueUserIsRequiredForSuccessfulDiagnostic(): void
    {
        self::assertNull((new \ReflectionMethod(DirectoryConnectionTester::class, 'requireUniqueEntry'))->invoke(null, ['count' => 1]));
    }

    #[DataProvider('invalidEntries')]
    public function testFailedEmptyOrAmbiguousSearchIsRejected(array|false $entries): void
    {
        $this->expectException(\RuntimeException::class);
        (new \ReflectionMethod(DirectoryConnectionTester::class, 'requireUniqueEntry'))->invoke(null, $entries);
    }

    public static function invalidEntries(): iterable
    {
        yield [false];
        yield [[]];
        yield [['count' => 0]];
        yield [['count' => 2]];
        yield [['count' => '1']];
    }
}
