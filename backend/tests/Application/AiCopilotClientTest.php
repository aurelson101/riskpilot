<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\AiCopilotClient;
use App\Entity\AiSettings;
use App\Entity\Organization;
use App\Security\SecretCipher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiCopilotClientTest extends TestCase
{
    public function testOpenAiCompatibleRequestUsesConfiguredModelAndGuardrails(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = compact('method', 'url', 'options');

            return new MockResponse(json_encode(['output_text' => 'Conseil sourcé [1].'], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new AiSettings(new Organization('Tenant'));
        $settings->configure('OPENAI', 'https://api.openai.com/v1', 'gpt-test', 'MINIMAL', '', true);
        $settings->setEncryptedApiKey($cipher->encrypt('provider-secret'));

        $answer = (new AiCopilotClient($http, $cipher))->ask(
            $settings,
            ['requirementReference' => 'ART-32', 'complianceStatus' => 'NOT_ASSESSED'],
            'Quelles preuves ?',
            [],
            'fr',
            'safety-user-1',
        );

        self::assertSame('Conseil sourcé [1].', $answer);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.openai.com/v1/responses', $captured['url']);
        self::assertSame('Authorization: Bearer provider-secret', $captured['options']['normalized_headers']['authorization'][0]);
        $body = json_decode($captured['options']['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('gpt-test', $body['model']);
        self::assertFalse($body['store']);
        self::assertSame('safety-user-1', $body['safety_identifier']);
        self::assertStringContainsString('Never mark an item compliant', $body['instructions']);
        self::assertStringContainsString('ART-32', $body['instructions']);
    }

    public function testGeminiRequestUsesNativeGenerateContentContract(): void
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = compact('method', 'url', 'options');

            return new MockResponse(json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Gemini answer [1].']]]]]], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new AiSettings(new Organization('Tenant'));
        $settings->configure('GEMINI', 'https://generativelanguage.googleapis.com/v1beta', 'gemini-test', 'MINIMAL', '', true);
        $settings->setEncryptedApiKey($cipher->encrypt('gemini-secret'));

        $answer = (new AiCopilotClient($http, $cipher))->ask($settings, ['requirementReference' => 'ART-32'], 'Help', [], 'en', 'safety-user-1');

        self::assertSame('Gemini answer [1].', $answer);
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent', $captured['url']);
        self::assertSame('x-goog-api-key: gemini-secret', $captured['options']['normalized_headers']['x-goog-api-key'][0]);
        $body = json_decode($captured['options']['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('user', $body['contents'][0]['role']);
        self::assertSame('Help', $body['contents'][0]['parts'][0]['text']);
    }

    public function testRiskDraftParsesAConstrainedStructuredResponse(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['output_text' => <<<'JSON'
```json
{"title":"Compromission du prestataire de paie","description":"Une compromission exposerait les dossiers employés et perturberait la paie.","scopeId":11,"assetId":22,"threatId":33,"likelihood":3,"impact":5,"rationale":"Impact élevé en raison des données personnelles ; vraisemblance à confirmer avec les contrôles du fournisseur."}
```
JSON], JSON_THROW_ON_ERROR), ['http_code' => 200]));
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new AiSettings(new Organization('Tenant'));
        $settings->configure('OPENAI', 'https://api.openai.com/v1', 'gpt-test', 'MINIMAL', '', true);
        $settings->setEncryptedApiKey($cipher->encrypt('provider-secret'));

        $draft = (new AiCopilotClient($http, $cipher))->draftRisk($settings, 'Crée un risque pour notre prestataire de paie.', [
            'scopes' => [['id' => 11, 'name' => 'Ressources humaines']],
            'assets' => [['id' => 22, 'name' => 'Paie SaaS']],
            'threats' => [['id' => 33, 'name' => 'Compromission fournisseur']],
        ], 'fr', 'safety-user-1');

        self::assertSame('Compromission du prestataire de paie', $draft['title']);
        self::assertSame(11, $draft['scopeId']);
        self::assertSame(15, $draft['likelihood'] * $draft['impact']);
        self::assertStringContainsString('à confirmer', $draft['rationale']);
    }
}
