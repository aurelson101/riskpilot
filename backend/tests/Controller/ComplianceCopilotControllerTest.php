<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AiSettings;
use App\Entity\AuditLog;
use App\Entity\ComplianceAssessment;
use App\Entity\ComplianceResult;
use App\Entity\Framework;
use App\Entity\Organization;
use App\Entity\Requirement;
use App\Entity\Scope;
use App\Entity\User;
use App\Security\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ComplianceCopilotControllerTest extends WebTestCase
{
    public function testContextIsTenantScopedAndChatRequiresEnabledSettings(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient(new MockResponse(json_encode(['output_text' => 'Réponse prudente et sourcée [1].'], JSON_THROW_ON_ERROR), ['http_code' => 200])));
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());

        $organization = new Organization('Primary');
        $otherOrganization = new Organization('Other');
        $viewer = new User('viewer@example.test', 'Victor', 'Viewer', $organization, [User::ROLE_VIEWER]);
        $other = new User('other@example.test', 'Other', 'Viewer', $otherOrganization, [User::ROLE_VIEWER]);
        $scope = new Scope('Production', 'ORGANIZATION', $organization);
        $framework = new Framework('RGPD', '2016/679');
        $requirement = (new Requirement($framework, 'ART-32', 'Sécurité du traitement', 'Sécurité'))->setDescription('Description contextuelle');
        $assessment = new ComplianceAssessment($organization, $framework, $scope, $viewer, new \DateTimeImmutable());
        $result = (new ComplianceResult($assessment, $requirement))->setComment('Commentaire interne')->setEvidence(['preuve.pdf']);
        foreach ([$organization, $otherOrganization, $viewer, $other, $scope, $framework, $requirement, $assessment, $result] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($viewer));
        $client->request('GET', '/api/compliance-results/'.$result->getId().'/copilot/context');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['enabled']);
        self::assertSame('ART-32', $payload['context']['requirementReference']);
        self::assertArrayNotHasKey('currentComment', $payload['context']);
        self::assertArrayNotHasKey('evidenceReferences', $payload['context']);

        $client->jsonRequest('POST', '/api/compliance-results/'.$result->getId().'/copilot', ['question' => 'Quelles preuves ?', 'consent' => true]);
        self::assertResponseStatusCodeSame(409);

        $managedOrganization = $manager->find(Organization::class, $organization->getId());
        self::assertInstanceOf(Organization::class, $managedOrganization);
        $settings = new AiSettings($managedOrganization);
        $settings->configure('OPENAI', 'https://api.openai.com/v1', 'gpt-test', 'CONTEXTUAL', '', true);
        $settings->setEncryptedApiKey(self::getContainer()->get(SecretCipher::class)->encrypt('provider-secret'));
        $manager->persist($settings);
        $manager->flush();
        $client->jsonRequest('POST', '/api/compliance-results/'.$result->getId().'/copilot', ['question' => 'Quelles preuves ?', 'consent' => true]);
        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Réponse prudente et sourcée [1].', $answer['answer']);
        self::assertFalse($answer['automaticWrite']);
        $audit = $manager->getRepository(AuditLog::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(AuditLog::class, $audit);
        self::assertSame('[REDACTED]', $audit->getNewValues()['request']['question']);
        self::assertSame('OPENAI', $audit->getNewValues()['entities'][0]['provider']);
        self::assertSame(hash('sha256', 'Quelles preuves ?'), $audit->getNewValues()['entities'][0]['questionHash']);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($other));
        $client->request('GET', '/api/compliance-results/'.$result->getId().'/copilot/context');
        self::assertResponseStatusCodeSame(404);
    }
}
