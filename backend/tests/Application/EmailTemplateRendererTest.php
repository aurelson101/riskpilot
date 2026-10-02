<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\EmailTemplateRenderer;
use PHPUnit\Framework\TestCase;

final class EmailTemplateRendererTest extends TestCase
{
    public function testPasswordResetUsesTheSelectedLanguageAndAnEncodedToken(): void
    {
        $templates = new EmailTemplateRenderer('https://riskpilot.example/');
        foreach (['fr' => 'Réinitialisation', 'en' => 'Reset your'] as $locale => $subject) {
            $email = $templates->passwordReset($locale, 'token&?#value');
            self::assertStringStartsWith($subject, $email['subject']);
            self::assertStringContainsString('https://riskpilot.example/reset-password?token=token%26%3F%23value', $email['message']);
            self::assertStringContainsString('30 minutes', $email['message']);
            self::assertStringContainsString('fr' === $locale ? 'usage unique' : 'single-use', $email['message']);
        }
        self::assertSame($templates->passwordReset('fr', 'token'), $templates->passwordReset('unsupported', 'token'));
    }

    public function testConnectionTestIsBilingualWithAFrenchFallback(): void
    {
        $templates = new EmailTemplateRenderer('https://riskpilot.example');
        self::assertSame('Test de messagerie RiskPilot', $templates->connectionTest('fr')['subject']);
        self::assertSame('RiskPilot email configuration test', $templates->connectionTest('en')['subject']);
        self::assertSame($templates->connectionTest('fr'), $templates->connectionTest('unsupported'));
        self::assertStringContainsString('Si vous recevez', $templates->connectionTest('fr')['message']);
        self::assertStringContainsString('If you received', $templates->connectionTest('en')['message']);
    }

    public function testNotificationIncludesOnlyAnInternalLinkWithALocalizedLabel(): void
    {
        $templates = new EmailTemplateRenderer('https://riskpilot.example/');
        self::assertSame("Reminder\n\nView in RiskPilot: https://riskpilot.example/actions?tab=calendar", $templates->notificationMessage('Reminder', '/actions?tab=calendar', 'en'));
        self::assertSame("Échéance\n\nConsulter dans RiskPilot : https://riskpilot.example/actions", $templates->notificationMessage('Échéance', '/actions', 'fr'));
        foreach ([null, '', 'https://evil.example', '//evil.example', '/\\evil.example', "/actions\r\nInjected", '/actions%0AInjected', '/actions%5Cevil', 'javascript:alert(1)'] as $link) {
            self::assertSame('Body', $templates->notificationMessage('Body', $link, 'en'));
        }
    }

    public function testUnsafeBaseUrlsCannotProduceNotificationLinks(): void
    {
        foreach (['javascript:alert(1)', 'https://user:pass@riskpilot.example', 'https://riskpilot.example?redirect=', 'https://riskpilot.example#fragment'] as $base) {
            self::assertSame('Body', (new EmailTemplateRenderer($base))->notificationMessage('Body', '/actions', 'fr'));
        }
    }

    public function testInvalidApplicationUrlCannotProduceAPasswordResetLink(): void
    {
        $this->expectException(\RuntimeException::class);
        (new EmailTemplateRenderer('javascript:alert(1)'))->passwordReset('en', 'token');
    }
}
