<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\OauthMailProvider;
use App\Entity\EmailSettings;
use App\Entity\Organization;
use App\Security\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OauthMailProviderTest extends TestCase
{
    public function testMicrosoftGraphUsesDelegatedMailSendAndTheConnectedSender(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = compact('method', 'url', 'options');

            return new MockResponse('', ['http_code' => 202]);
        });
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new EmailSettings(new Organization('Tenant'));
        $settings->configureOauth('MICROSOFT_365', 'client-id', $cipher->encrypt('client-secret'), 'organizations', 'RiskPilot GRC', 'reply@example.test');
        $settings->connectOauth($cipher->encrypt('access-token'), $cipher->encrypt('refresh-token'), new \DateTimeImmutable('+1 hour'), 'riskpilot@example.test');
        $entityManager = $this->createMock(EntityManagerInterface::class);

        (new OauthMailProvider($cipher, $entityManager, $http))->send($settings, 'recipient@example.test', 'Risk review', 'A review is due.');

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://graph.microsoft.com/v1.0/me/sendMail', $captured['url']);
        self::assertSame('Authorization: Bearer access-token', $captured['options']['normalized_headers']['authorization'][0]);
        $payload = json_decode($captured['options']['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('riskpilot@example.test', $payload['message']['from']['emailAddress']['address']);
        self::assertSame('RiskPilot GRC', $payload['message']['from']['emailAddress']['name']);
        self::assertSame('reply@example.test', $payload['message']['replyTo'][0]['emailAddress']['address']);
        self::assertTrue($payload['saveToSentItems']);
    }
}
