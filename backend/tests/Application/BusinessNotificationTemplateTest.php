<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\EmailTemplateRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessNotificationTemplateTest extends TestCase
{
    #[DataProvider('templates')]
    public function testAllBusinessTypesAreBilingual(string $type, array $parameters, string $englishTitle): void
    {
        $templates = new EmailTemplateRenderer('https://riskpilot.example');
        $fr = $templates->businessNotification($type, 'fr', $parameters);
        $en = $templates->businessNotification($type, 'en', $parameters);
        self::assertSame($englishTitle, $en['subject']);
        self::assertNotSame($fr['message'], $en['message']);
        self::assertStringContainsString('Business item', $en['message']);
        self::assertSame($fr, $templates->businessNotification($type, 'unsupported', $parameters));
        self::assertStringNotContainsString('<script>', $templates->html($en['subject'], $en['message']."<script>untrusted</script>"));
    }

    public static function templates(): iterable
    {
        $due = new \DateTimeImmutable('2030-01-02');
        yield ['ACTION_ASSIGNED', ['Business item', $due], 'New action assigned'];
        yield ['ACTION_OWNER_CHANGED', ['Business item', $due], 'Action reassigned'];
        yield ['ACTION_OVERDUE', ['Business item', $due], 'Overdue action'];
        yield ['ACTION_DUE_SOON', ['Business item', $due], 'Action due soon'];
        yield ['RISK_ACCEPTANCE_EXPIRED', ['Business item'], 'Risk acceptance expired'];
        yield ['RISK_REVIEW_REMINDER', ['Business item', $due, 'Campaign'], 'Risk review reminder'];
        yield ['OPERATIONAL_REMINDER', ['Business item', $due], 'Operational deadline reminder'];
        yield ['CRITICAL_RISK_CREATED', ['Business item', 25], 'Critical risk created'];
        yield ['RISK_REVIEW_REQUIRED', ['Business item'], 'Risk validation required'];
        yield ['COMPLIANCE_ASSESSMENT_COMPLETED', ['Business item', '2022', 75.5], 'Compliance assessment completed'];
        yield ['RISK_ACCEPTANCE_REQUIRED', ['Business item'], 'Risk acceptance decision required'];
        yield ['RISK_ACCEPTANCE_DECIDED', ['Business item', 'APPROVED'], 'Risk acceptance decision'];
        yield ['RISK_REVIEW_CAMPAIGN', ['Business item', 3], 'Risk review campaign assigned'];
    }

    public function testDecisionsAndDatesAreTranslatedWithoutChangingData(): void
    {
        $templates = new EmailTemplateRenderer('https://riskpilot.example');
        foreach (['APPROVED' => ['approuvée', 'approved'], 'REJECTED' => ['refusée', 'rejected']] as $status => [$fr, $en]) {
            self::assertStringContainsString($fr, $templates->businessNotification('RISK_ACCEPTANCE_DECIDED', 'fr', ['Risk', $status])['message']);
            self::assertStringContainsString($en, $templates->businessNotification('RISK_ACCEPTANCE_DECIDED', 'en', ['Risk', $status])['message']);
        }
        $parameters = ['Audit', new \DateTimeImmutable('2030-01-02')];
        self::assertStringContainsString('02/01/2030', $templates->businessNotification('ACTION_ASSIGNED', 'fr', $parameters)['message']);
        self::assertStringContainsString('2030-01-02', $templates->businessNotification('ACTION_ASSIGNED', 'en', $parameters)['message']);
    }
}
