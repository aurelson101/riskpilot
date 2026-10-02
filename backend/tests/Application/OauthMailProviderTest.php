<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\OauthMailProvider;
use App\Entity\EmailSettings;
use App\Entity\Organization;
use App\Security\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OauthMailProviderTest extends TestCase
{
    #[DataProvider('invalidTokens')]
    public function testInvalidTokenResponsesAreRejected(array $payload): void
    {
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new EmailSettings(new Organization('Tenant'));
        $settings->configureOauth('GOOGLE_WORKSPACE', 'client-id', $cipher->encrypt('client-secret'), null, 'RiskPilot', null);
        $provider = new OauthMailProvider($cipher, $this->createMock(EntityManagerInterface::class), new MockHttpClient(new MockResponse(json_encode($payload))));
        $this->expectException(\RuntimeException::class);
        $provider->exchangeCode($settings, 'https://riskpilot.example/callback', 'code', 'state');
    }

    public static function invalidTokens(): iterable
    {
        yield 'empty access token' => [['access_token' => ' ', 'expires_in' => 3600]];
        yield 'zero lifetime' => [['access_token' => 'token', 'expires_in' => 0]];
        yield 'negative lifetime' => [['access_token' => 'token', 'expires_in' => -1]];
        yield 'string lifetime' => [['access_token' => 'token', 'expires_in' => '3600']];
        yield 'unbounded lifetime' => [['access_token' => 'token', 'expires_in' => 86401]];
        yield 'empty refresh token' => [['access_token' => 'token', 'refresh_token' => '', 'expires_in' => 3600]];
        yield 'invalid refresh token' => [['access_token' => 'token', 'refresh_token' => ['secret'], 'expires_in' => 3600]];
    }

    public function testRefreshPreservesTheActualShortTokenLifetime(): void
    {
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new EmailSettings(new Organization('Tenant'));
        $settings->configureOauth('MICROSOFT_365', 'client-id', $cipher->encrypt('client-secret'), 'organizations', 'RiskPilot', null);
        $settings->connectOauth($cipher->encrypt('expired-token'), $cipher->encrypt('refresh-token'), new \DateTimeImmutable('-1 minute'), 'sender@example.test');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $http = new MockHttpClient([
            new MockResponse('{"access_token":"new-token","expires_in":10}'),
            new MockResponse('', ['http_code' => 202]),
        ]);
        (new OauthMailProvider($cipher, $em, $http))->send($settings, 'recipient@example.test', 'Review', 'Body');
        self::assertSame('new-token', $cipher->decrypt($settings->getEncryptedAccessToken()));
        self::assertLessThanOrEqual(time() + 10, $settings->getAccessTokenExpiresAt()->getTimestamp());
        self::assertGreaterThan(time(), $settings->getAccessTokenExpiresAt()->getTimestamp());
    }

    public function testMicrosoftIdentityFallsBackToUpnAndRequestsAreBounded(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame(15.0, $options['timeout']);
            self::assertSame(30.0, $options['max_duration']);
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse('{"mail":"","userPrincipalName":"owner@example.test"}');
        });
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new EmailSettings(new Organization('Tenant'));
        $settings->configureOauth('MICROSOFT_365', 'client-id', $cipher->encrypt('client-secret'), 'organizations', 'RiskPilot', null);
        self::assertSame('owner@example.test', (new OauthMailProvider($cipher, $this->createMock(EntityManagerInterface::class), $http))->connectedEmail($settings, 'token'));
    }

    public function testGoogleExplicitlyUnverifiedIdentityIsRejected(): void
    {
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new EmailSettings(new Organization('Tenant'));
        $settings->configureOauth('GOOGLE_WORKSPACE', 'client-id', $cipher->encrypt('client-secret'), null, 'RiskPilot', null);
        $provider = new OauthMailProvider($cipher, $this->createMock(EntityManagerInterface::class), new MockHttpClient(new MockResponse('{"email":"owner@example.test","email_verified":false}')));
        $this->expectException(\RuntimeException::class);
        $provider->connectedEmail($settings, 'token');
    }

    public function testNonOauthProviderCannotFallThroughToMicrosoft(): void
    {
        $provider = new OauthMailProvider(new SecretCipher('test-secret-at-least-32-characters-long'), $this->createMock(EntityManagerInterface::class), new MockHttpClient());
        $this->expectException(\InvalidArgumentException::class);
        $provider->authorizationUrl(new EmailSettings(new Organization('Tenant')), 'https://riskpilot.example/callback', 'state');
    }

    #[DataProvider('invalidMessages')]
    public function testInvalidMessageIsRejectedWithoutHttpRequests(string $recipient, string $subject): void
    {
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new EmailSettings(new Organization('Tenant'));
        $settings->configureOauth('MICROSOFT_365', 'client-id', $cipher->encrypt('client-secret'), 'organizations', 'RiskPilot', null);
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('Invalid message must not make an HTTP request.');
        });
        $this->expectException(\InvalidArgumentException::class);
        (new OauthMailProvider($cipher, $this->createMock(EntityManagerInterface::class), $http))->send($settings, $recipient, $subject, 'Body');
    }

    public static function invalidMessages(): iterable
    {
        yield 'recipient' => ['invalid', 'Subject'];
        yield 'empty subject' => ['owner@example.test', ''];
        yield 'subject injection' => ['owner@example.test', "Subject\r\nBcc: evil@example.test"];
        yield 'long subject' => ['owner@example.test', str_repeat('a', 256)];
    }

    public function testOauthAuthorizationUsesMinimalScopesStateAndPkce(): void
    {
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $provider = new OauthMailProvider($cipher, $entityManager, new MockHttpClient());
        $cases = [
            ['GOOGLE_WORKSPACE', null, ['openid', 'email', 'https://www.googleapis.com/auth/gmail.send']],
            ['MICROSOFT_365', 'organizations', ['openid', 'email', 'offline_access', 'User.Read', 'Mail.Send']],
        ];

        foreach ($cases as [$providerName, $tenant, $expectedScopes]) {
            $settings = new EmailSettings(new Organization('Tenant'));
            $settings->configureOauth($providerName, 'client-id', $cipher->encrypt('client-secret'), $tenant, 'RiskPilot', null);
            $url = $provider->authorizationUrl($settings, 'https://riskpilot.test/callback', 'random-state');
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            self::assertSame('random-state', $query['state']);
            self::assertSame('S256', $query['code_challenge_method']);
            self::assertSame(43, strlen($query['code_challenge']));
            self::assertSame($expectedScopes, explode(' ', $query['scope']));
            self::assertArrayNotHasKey('client_secret', $query);
        }
    }

    public function testTokenExchangeUsesThePkceVerifierBoundToState(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = compact('method', 'url', 'options');

            return new MockResponse(json_encode(['access_token' => 'access-token', 'expires_in' => 3600], JSON_THROW_ON_ERROR));
        });
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new EmailSettings(new Organization('Tenant'));
        $settings->configureOauth('GOOGLE_WORKSPACE', 'client-id', $cipher->encrypt('client-secret'), null, 'RiskPilot', null);
        $provider = new OauthMailProvider($cipher, $this->createMock(EntityManagerInterface::class), $http);
        $authorizationUrl = $provider->authorizationUrl($settings, 'https://riskpilot.test/callback', 'random-state');
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationQuery);

        $provider->exchangeCode($settings, 'https://riskpilot.test/callback', 'authorization-code', 'random-state');
        parse_str($captured['options']['body'], $tokenBody);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $tokenBody['code_verifier'], true)), '+/', '-_'), '=');
        self::assertSame($authorizationQuery['code_challenge'], $challenge);
        self::assertSame('authorization-code', $tokenBody['code']);
        self::assertSame('client-secret', $tokenBody['client_secret']);
    }

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
        self::assertSame('Text', $payload['message']['body']['contentType']);
    }

    public function testGoogleUsesGmailSendForTheConnectedAccount(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = compact('method', 'url', 'options');

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new EmailSettings(new Organization('Tenant'));
        $settings->configureOauth('GOOGLE_WORKSPACE', 'client-id', $cipher->encrypt('client-secret'), null, 'RiskPilot GRC', 'reply@example.test');
        $settings->connectOauth($cipher->encrypt('access-token'), $cipher->encrypt('refresh-token'), new \DateTimeImmutable('+1 hour'), 'riskpilot@example.test');

        (new OauthMailProvider($cipher, $this->createMock(EntityManagerInterface::class), $http))->send($settings, 'recipient@example.test', 'Risk review', 'A review is due.');

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $captured['url']);
        self::assertSame('Authorization: Bearer access-token', $captured['options']['normalized_headers']['authorization'][0]);
        $payload = json_decode($captured['options']['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertNotEmpty($payload['raw']);
    }

    public function testHtmlIsSentThroughGraphAndAsMultipartAlternativeThroughGmail(): void
    {
        foreach (['MICROSOFT_365', 'GOOGLE_WORKSPACE'] as $providerName) {
            $captured = [];
            $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured = $options;
                return new MockResponse('{}', ['http_code' => 200]);
            });
            $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
            $settings = new EmailSettings(new Organization('Tenant'));
            $settings->configureOauth($providerName, 'client-id', $cipher->encrypt('client-secret'), 'organizations', 'RiskPilot', null);
            $settings->connectOauth($cipher->encrypt('access-token'), $cipher->encrypt('refresh-token'), new \DateTimeImmutable('+1 hour'), 'sender@example.test');
            (new OauthMailProvider($cipher, $this->createMock(EntityManagerInterface::class), $http))->send($settings, 'recipient@example.test', 'Test', 'Plain body', '<p>HTML body</p>');
            $payload = json_decode($captured['body'], true, flags: JSON_THROW_ON_ERROR);
            if ('MICROSOFT_365' === $providerName) {
                self::assertSame('HTML', $payload['message']['body']['contentType']);
                self::assertSame('<p>HTML body</p>', $payload['message']['body']['content']);
            } else {
                $mime = base64_decode(strtr($payload['raw'], '-_', '+/'), true);
                self::assertIsString($mime);
                self::assertStringContainsString('multipart/alternative', $mime);
                self::assertStringContainsString('text/plain', $mime);
                self::assertStringContainsString('text/html', $mime);
                self::assertStringContainsString('Plain body', $mime);
                self::assertStringContainsString('HTML body', $mime);
            }
        }
    }
}
