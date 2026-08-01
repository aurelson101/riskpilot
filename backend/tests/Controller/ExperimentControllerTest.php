<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Organization;
use App\Entity\SecurityControl;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExperimentControllerTest extends WebTestCase
{
    public function testAssistantAndLibraryStayTenantScopedAndHumanControlled(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($manager);
        $schema->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $schema->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $first = new Organization('Primary');
        $second = new Organization('Other');
        $admin = new User('admin@example.test', 'Alice', 'Admin', $first, [User::ROLE_ADMIN]);
        $riskManager = new User('risk@example.test', 'Rita', 'Risk', $first, [User::ROLE_RISK_MANAGER]);
        $otherAdmin = new User('other@example.test', 'Other', 'Admin', $second, [User::ROLE_ADMIN]);
        $viewer = new User('viewer@example.test', 'Victor', 'Viewer', $first, [User::ROLE_VIEWER]);
        $control = new SecurityControl('Access control review', 'Identity', $first);
        foreach ([$first, $second, $admin, $riskManager, $otherAdmin, $viewer, $control] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($admin));
        $client->jsonRequest('PUT', '/api/experiments/settings', ['assistantEnabled' => true, 'allowedKinds' => ['QUESTION_SUGGESTIONS']]);
        self::assertResponseIsSuccessful();

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($riskManager));
        $client->jsonRequest('POST', '/api/experiments/assistant/proposals', ['kind' => 'QUESTION_SUGGESTIONS', 'context' => []]);
        self::assertResponseStatusCodeSame(201);
        $proposal = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($proposal['appliedAutomatically']);
        self::assertNotEmpty($proposal['sources']);
        self::assertNotEmpty($client->getResponse()->headers->get('X-Request-ID'));

        $client->request('GET', '/api/experiments/assistant/proposals?page=1&limit=1000');
        $page = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(100, $page['limit']);
        self::assertSame(1, $page['total']);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($viewer));
        $client->jsonRequest('POST', '/api/experiments/assistant/proposals', ['kind' => 'QUESTION_SUGGESTIONS', 'context' => []]);
        self::assertResponseStatusCodeSame(403);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($otherAdmin));
        $client->jsonRequest('POST', '/api/experiments/assistant/proposals/'.$proposal['id'].'/validate', ['decision' => 'APPROVED', 'comment' => 'Cross tenant']);
        self::assertResponseStatusCodeSame(404);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($admin));
        $client->jsonRequest('POST', '/api/experiments/assistant/proposals/'.$proposal['id'].'/validate', ['decision' => 'APPROVED', 'comment' => 'Sources vérifiées']);
        self::assertResponseIsSuccessful();
        self::assertSame('APPROVED', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['status']);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($riskManager));
        $client->jsonRequest('POST', '/api/experiments/library', ['key' => 'control.access-review', 'kind' => 'CONTROL', 'title' => 'Access review', 'content' => ['category' => 'Identity', 'objective' => 'Review access'], 'source' => 'Internal', 'license' => 'Internal use']);
        self::assertResponseStatusCodeSame(201);
        $item = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $client->jsonRequest('POST', '/api/experiments/library/'.$item['id'].'/submit');
        self::assertResponseIsSuccessful();

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($admin));
        $client->jsonRequest('POST', '/api/experiments/library/'.$item['id'].'/approve');
        self::assertResponseIsSuccessful();
        self::assertSame('APPROVED', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['status']);
        $client->jsonRequest('POST', '/api/experiments/library/'.$item['id'].'/revisions', ['title' => 'Access review v2', 'content' => ['category' => 'Identity', 'objective' => 'Quarterly review']]);
        self::assertResponseStatusCodeSame(201);
        $revision = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $revision['version']);
        self::assertSame($item['id'], $revision['supersedesId']);

        $import = ['schemaVersion' => 1, 'items' => [['key' => 'threat.ransomware', 'kind' => 'THREAT', 'title' => 'Ransomware', 'content' => ['category' => 'Malware'], 'source' => 'Internal', 'license' => 'Internal use']]];
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($riskManager));
        $client->jsonRequest('POST', '/api/experiments/library/import', $import);
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['dryRun']);
        $client->jsonRequest('POST', '/api/experiments/library/import', $import + ['commit' => true]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['imported']);

        self::assertGreaterThan(0, $manager->getRepository(AuditLog::class)->count(['organization' => $first]));

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($otherAdmin));
        $client->request('GET', '/api/experiments/library');
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['items']);
    }
}
