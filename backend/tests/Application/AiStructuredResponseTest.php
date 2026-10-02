<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\AiCopilotClient;
use App\Entity\AiSettings;
use App\Entity\Organization;
use App\Security\SecretCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiStructuredResponseTest extends TestCase
{
    #[DataProvider('invalidRiskDrafts')]
    public function testRiskDraftCannotInventRelationsOrCoerceData(array $override): void
    {
        [$client, $settings] = $this->provider([...$this->risk(), ...$override]);
        $this->expectException(\RuntimeException::class);
        $client->draftRisk($settings, 'Prepare a draft', $this->catalog(), 'en', 'test-safety');
    }

    public static function invalidRiskDrafts(): iterable
    {
        yield [['title' => ['injected']]];
        yield [['description' => true]];
        yield [['rationale' => 1]];
        yield [['scopeId' => '1']];
        yield [['assetId' => true]];
        yield [['threatId' => 2.5]];
        yield [['likelihood' => '3']];
        yield [['impact' => false]];
        yield [['scopeId' => 99]];
        yield [['assetId' => 99]];
        yield [['threatId' => 99]];
    }

    #[DataProvider('invalidComplianceDrafts')]
    public function testComplianceDraftCannotInventRelationsOrCoerceData(array $override): void
    {
        [$client, $settings] = $this->provider([...$this->compliance(), ...$override]);
        $this->expectException(\RuntimeException::class);
        $client->draftComplianceAction($settings, 'Prepare a draft', [['id' => 1, 'label' => 'Control', 'status' => 'NON_COMPLIANT']], 'en', 'test-safety');
    }

    public static function invalidComplianceDrafts(): iterable
    {
        yield [['title' => ['injected']]];
        yield [['priority' => ['HIGH']]];
        yield [['actionType' => true]];
        yield [['complianceResultId' => '1']];
        yield [['dueInDays' => 3.5]];
        yield [['complianceResultId' => 99]];
    }

    public function testValidDraftsRemainAvailable(): void
    {
        [$client, $settings] = $this->provider($this->risk());
        self::assertSame(1, $client->draftRisk($settings, 'Draft', $this->catalog(), 'en', 'safety')['scopeId']);
        [$client, $settings] = $this->provider($this->compliance());
        self::assertSame(1, $client->draftComplianceAction($settings, 'Draft', [['id' => 1, 'label' => 'Control', 'status' => 'NON_COMPLIANT']], 'en', 'safety')['complianceResultId']);
    }

    #[DataProvider('invalidPilotResponses')]
    public function testPilotRejectsMalformedAnswerAndActionList(array $payload): void
    {
        [$client, $settings] = $this->provider($payload);
        $this->expectException(\RuntimeException::class);
        $client->pilot($settings, 'Help', [], 'en', 'safety', '/', []);
    }

    public static function invalidPilotResponses(): iterable
    {
        yield [['answer' => ['text'], 'actions' => []]];
        yield [['answer' => 'Help', 'actions' => ['one' => ['type' => 'NAVIGATE']]]];
    }

    public function testPilotDiscardsMalformedUnauthorizedAndDuplicateActions(): void
    {
        $capabilities = [['type' => 'NAVIGATE', 'label' => 'Risks', 'path' => '/risks'], ['type' => 'OPEN_RISK_DRAFT', 'label' => 'Draft']];
        [$client, $settings] = $this->provider(['answer' => 'Prepare the governed draft.', 'actions' => [
            ['type' => 'DELETE_ALL', 'label' => 'Delete'], ['type' => 'NAVIGATE', 'label' => 'External', 'path' => 'https://evil.example'],
            ['type' => ['NAVIGATE'], 'label' => 'Malformed'], ['type' => 'NAVIGATE', 'label' => ['Wrong type']],
            ['type' => 'NAVIGATE', 'label' => "Injected\nLabel", 'path' => '/risks'],
            ['type' => 'NAVIGATE', 'label' => 'Risks', 'path' => '/risks'], ['type' => 'NAVIGATE', 'label' => 'Duplicate', 'path' => '/risks'],
            ['type' => 'OPEN_RISK_DRAFT', 'label' => 'Draft', 'path' => 'https://evil.example'],
        ]]);
        $result = $client->pilot($settings, 'Help', [], 'en', 'safety', '/', $capabilities);
        self::assertSame($capabilities, $result['actions']);
    }

    private function provider(array $payload): array
    {
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new AiSettings(new Organization('Tenant'));
        $settings->configure('MISTRAL', 'https://api.mistral.ai/v1', 'codestral-latest', 'MINIMAL', '', true);
        $settings->setEncryptedApiKey($cipher->encrypt('test-only-placeholder'));
        $response = new MockResponse(json_encode(['choices' => [['message' => ['content' => json_encode($payload, JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR));

        return [new AiCopilotClient(new MockHttpClient($response), $cipher), $settings];
    }

    private function risk(): array
    {
        return ['title' => 'Supplier risk', 'description' => 'Review the supplier.', 'rationale' => 'Confirm evidence.', 'scopeId' => 1, 'assetId' => 1, 'threatId' => 1, 'likelihood' => 3, 'impact' => 4];
    }

    private function compliance(): array
    {
        return ['title' => 'Access review', 'description' => 'Review privileged accounts.', 'rationale' => 'Confirm evidence.', 'complianceResultId' => 1, 'priority' => 'HIGH', 'actionType' => 'ORGANIZATIONAL', 'dueInDays' => 30];
    }

    private function catalog(): array
    {
        return ['scopes' => [['id' => 1, 'name' => 'ISMS']], 'assets' => [['id' => 1, 'name' => 'Asset']], 'threats' => [['id' => 1, 'name' => 'Threat']]];
    }
}
