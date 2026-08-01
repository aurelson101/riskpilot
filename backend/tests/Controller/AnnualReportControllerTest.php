<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AnnualReportControllerTest extends WebTestCase
{
    public function testAnnualClassificationVersioningExportAndAcl(): void
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
        $manager->persist(new AuditLog($organization, $managerUser, 'UPDATE', 'RiskScenario', '42', ['status' => 'TREATED'], '127.0.0.1', 'PHPUnit'));
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $year = (int) date('Y');

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($reader));
        $client->request('GET', '/api/annual-reports/'.$year);
        self::assertResponseIsSuccessful();
        $preview = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $preview['totals']['activities']);
        self::assertSame(1, $preview['byDomain']['RISKS']);
        self::assertArrayNotHasKey('newValues', $preview['activities'][0]);
        $client->jsonRequest('POST', '/api/annual-reports/'.$year.'/generate');
        self::assertResponseStatusCodeSame(403);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($managerUser));
        $client->jsonRequest('POST', '/api/annual-reports/'.$year.'/generate');
        self::assertResponseStatusCodeSame(201);
        $saved = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $saved['version']);
        $client->jsonRequest('POST', '/api/annual-reports/'.$year.'/generate');
        self::assertResponseStatusCodeSame(201);
        $second = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $second['version']);

        $client->request('GET', '/api/annual-reports/saved/'.$saved['id'].'/export?format=json');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment; filename="riskpilot-rapport-annuel-', (string) $client->getResponse()->headers->get('Content-Disposition'));
    }
}
