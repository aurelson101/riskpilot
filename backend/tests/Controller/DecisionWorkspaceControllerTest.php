<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\OperationalRecord;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DecisionWorkspaceControllerTest extends WebTestCase
{
    public function testProjectGatesSavedViewPrivacyAndGovernedReport(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $organization = new Organization('Primary');
        $managerUser = new User('manager@example.test', 'Risk', 'Manager', $organization, [User::ROLE_RISK_MANAGER]);
        $reader = new User('reader@example.test', 'View', 'Reader', $organization, [User::ROLE_VIEWER]);
        $manager->persist($organization);
        $manager->persist($managerUser);
        $manager->persist($reader);
        $legacyRun = new OperationalRecord($organization, 'REPORT_RUN', 'Legacy decision report', ['blocks' => ['risks'], 'snapshot' => ['risks' => 2]]);
        $legacyRun->update($legacyRun->getTitle(), 'COMPLETED', $legacyRun->getDetails(), $managerUser, null);
        $manager->persist($legacyRun);
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($managerUser));

        $client->request('GET', '/api/decision/reports/'.$legacyRun->getId().'/export?format=json');
        self::assertResponseIsSuccessful();
        $legacyExport = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Primary', $legacyExport['details']['organization']);
        self::assertSame('Risk Manager', $legacyExport['details']['generatedBy']);

        $client->jsonRequest('POST', '/api/operations/records', ['type' => 'SECURITY_PROJECT', 'title' => 'New service', 'status' => 'ACTIVE', 'ownerId' => $managerUser->getId(), 'details' => ['assetIds' => [], 'riskIds' => [], 'actionIds' => [], 'milestones' => []]]);
        self::assertResponseStatusCodeSame(201);
        $project = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $client->jsonRequest('POST', '/api/decision/projects/'.$project['id'].'/transition', ['status' => 'IN_PROGRESS']);
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', '/api/decision/projects/'.$project['id'].'/transition', ['status' => 'COMPLETED']);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('POST', '/api/operations/records', ['type' => 'SAVED_VIEW', 'title' => 'Private board', 'status' => 'ACTIVE', 'ownerId' => $managerUser->getId(), 'details' => ['shared' => false]]);
        self::assertResponseStatusCodeSame(201);
        $privateView = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $client->jsonRequest('POST', '/api/operations/records', ['type' => 'REPORT_TEMPLATE', 'title' => 'Management report', 'status' => 'ACTIVE', 'ownerId' => $managerUser->getId(), 'details' => ['version' => '1', 'blocks' => ['risks'], 'approved' => true, 'approvedBy' => 'Risk Manager']]);
        self::assertResponseStatusCodeSame(201);
        $template = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->jsonRequest('POST', '/api/decision/reports/'.$template['id'].'/run');
        self::assertResponseStatusCodeSame(201);
        $run = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('REPORT_RUN', $run['type']);
        self::assertArrayHasKey('snapshot', $run['details']);
        self::assertSame('Primary', $run['details']['organization']);
        self::assertSame('Risk Manager', $run['details']['generatedBy']);
        $client->request('GET', '/api/decision/reports/'.$run['id'].'/export?format=pdf');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
        $pdf = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(20_000, strlen($pdf));

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($reader));
        $client->request('GET', '/api/decision/views/'.$privateView['id'].'/snapshot');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/api/operations/records?type=SAVED_VIEW');
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }
}
