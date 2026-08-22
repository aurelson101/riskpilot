<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AiSettings;
use App\Entity\Asset;
use App\Entity\AuditLog;
use App\Entity\ComplianceAssessment;
use App\Entity\ComplianceResult;
use App\Entity\Framework;
use App\Entity\Organization;
use App\Entity\Requirement;
use App\Entity\Scope;
use App\Entity\Threat;
use App\Entity\User;
use App\Security\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GlobalCopilotControllerTest extends WebTestCase
{
    public function testGlobalChatIsConfiguredTenantScopedAuditedAndNeverWritesAutomatically(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            new MockResponse(json_encode(['output_text' => 'Commençons par identifier le périmètre.'], JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse(json_encode(['output_text' => '{"title":"Rançongiciel chez le prestataire","description":"Le service externalisé pourrait être indisponible et les données exposées.","scopeId":1,"assetId":1,"threatId":1,"likelihood":3,"impact":4,"rationale":"Le niveau doit être confirmé après revue des contrôles du prestataire."}'], JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse(json_encode(['output_text' => '{"title":"Formaliser la revue des accès","description":"Documenter une revue trimestrielle des accès privilégiés, son responsable, les écarts et les preuves de clôture.","complianceResultId":1,"priority":"HIGH","actionType":"ORGANIZATIONAL","dueInDays":60,"rationale":"L’exigence est partiellement satisfaite ; la fréquence et les preuves doivent être confirmées."}'], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]));
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());

        $organization = new Organization('Primary');
        $otherOrganization = new Organization('Other');
        $managerUser = new User('manager@example.test', 'Risk', 'Manager', $organization, [User::ROLE_RISK_MANAGER]);
        $other = new User('other@example.test', 'Other', 'User', $otherOrganization, [User::ROLE_RISK_MANAGER]);
        $scope = new Scope('Prestataires critiques', 'ORGANIZATION', $organization);
        $asset = new Asset('Service de paie', 'CLOUD_SERVICE', $scope, $organization);
        $threat = new Threat('Rançongiciel fournisseur', 'CYBER', $organization);
        $framework = new Framework('ISO 27001', '2022');
        $requirement = new Requirement($framework, 'A.5.18', 'Droits d’accès', 'Organisation');
        $assessment = new ComplianceAssessment($organization, $framework, $scope, $managerUser, new \DateTimeImmutable());
        $complianceResult = (new ComplianceResult($assessment, $requirement))->setComplianceStatus('PARTIAL');
        $foreignFramework = new Framework('Foreign', '1');
        $foreignRequirement = new Requirement($foreignFramework, 'F.1', 'Foreign requirement', 'Other');
        $foreignAssessment = new ComplianceAssessment($otherOrganization, $foreignFramework, new Scope('Foreign scope', 'ORGANIZATION', $otherOrganization), $other, new \DateTimeImmutable());
        $foreignResult = (new ComplianceResult($foreignAssessment, $foreignRequirement))->setComplianceStatus('NON_COMPLIANT');
        foreach ([$organization, $otherOrganization, $managerUser, $other, $scope, $asset, $threat, $framework, $requirement, $assessment, $complianceResult, $foreignFramework, $foreignRequirement, $foreignAssessment->getScope(), $foreignAssessment, $foreignResult] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($managerUser));

        $client->request('GET', '/api/copilot/context');
        self::assertResponseIsSuccessful();
        $context = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($context['enabled']);
        self::assertContains('RISK_DRAFT', $context['capabilities']);
        self::assertContains('COMPLIANCE_ACTION_DRAFT', $context['capabilities']);
        self::assertFalse($context['automaticWrite']);
        $client->jsonRequest('POST', '/api/copilot', ['question' => 'Aide-moi à créer un risque tiers', 'consent' => true]);
        self::assertResponseStatusCodeSame(409);

        $managedOrganization = $manager->find(Organization::class, $organization->getId());
        self::assertInstanceOf(Organization::class, $managedOrganization);
        $settings = new AiSettings($managedOrganization);
        $settings->configure('OPENAI', 'https://api.openai.com/v1', 'gpt-test', 'MINIMAL', '', true);
        $settings->setEncryptedApiKey(self::getContainer()->get(SecretCipher::class)->encrypt('provider-secret'));
        $manager->persist($settings);
        $manager->flush();

        $client->jsonRequest('POST', '/api/copilot', ['question' => 'Aide-moi à créer un risque tiers', 'consent' => true]);
        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Commençons par identifier le périmètre.', $answer['answer']);
        self::assertFalse($answer['automaticWrite']);
        $audit = $manager->getRepository(AuditLog::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(AuditLog::class, $audit);
        self::assertSame('[REDACTED]', $audit->getNewValues()['request']['question']);
        self::assertSame('GLOBAL_COPILOT', $audit->getNewValues()['entities'][0]['workflow']);

        $client->jsonRequest('POST', '/api/copilot/risk-draft', ['prompt' => 'Crée un risque de rançongiciel chez notre prestataire de paie.', 'consent' => true]);
        self::assertResponseIsSuccessful();
        $generated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Rançongiciel chez le prestataire', $generated['draft']['title']);
        self::assertSame($scope->getId(), $generated['draft']['scopeId']);
        self::assertSame($asset->getId(), $generated['draft']['assetId']);
        self::assertSame($threat->getId(), $generated['draft']['threatId']);
        self::assertFalse($generated['automaticWrite']);
        self::assertCount(0, $manager->getRepository(\App\Entity\RiskScenario::class)->findAll());
        $draftAudit = $manager->getRepository(AuditLog::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame('RISK_DRAFT', $draftAudit->getNewValues()['entities'][0]['workflow']);
        self::assertSame('[REDACTED]', $draftAudit->getNewValues()['request']['prompt']);

        $client->request('GET', '/api/copilot/compliance-catalog');
        self::assertResponseIsSuccessful();
        $catalog = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $catalog['items']);
        self::assertSame($complianceResult->getId(), $catalog['items'][0]['id']);
        self::assertStringContainsString('A.5.18', $catalog['items'][0]['label']);

        $client->jsonRequest('POST', '/api/copilot/compliance-action-draft', ['prompt' => 'Prépare une action pour formaliser la revue trimestrielle des accès.', 'consent' => true]);
        self::assertResponseIsSuccessful();
        $actionDraft = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Formaliser la revue des accès', $actionDraft['draft']['title']);
        self::assertSame($complianceResult->getId(), $actionDraft['draft']['complianceResultId']);
        self::assertSame('HIGH', $actionDraft['draft']['priority']);
        self::assertSame(60, $actionDraft['draft']['dueInDays']);
        self::assertFalse($actionDraft['automaticWrite']);
        self::assertCount(0, $manager->getRepository(\App\Entity\ActionPlan::class)->findAll());
        $actionDraftAudit = $manager->getRepository(AuditLog::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame('COMPLIANCE_ACTION_DRAFT', $actionDraftAudit->getNewValues()['entities'][0]['workflow']);
        self::assertSame('[REDACTED]', $actionDraftAudit->getNewValues()['request']['prompt']);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($other));
        $client->request('GET', '/api/copilot/context');
        self::assertResponseIsSuccessful();
        $otherContext = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($otherContext['enabled']);
    }
}
