<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Compliance\StarterFrameworkCatalog;
use App\Entity\Organization;
use App\Entity\Scope;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TisaxControllerTest extends WebTestCase
{
    public function testTisaxDeclarationTargetMaturitySummaryAndSpreadsheetExport(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $organization = new Organization('Primary');
        $other = new Organization('Other');
        $manager_ = new User('rm@example.test', 'Rita', 'Manager', $organization, [User::ROLE_RISK_MANAGER]);
        $foreign = new User('foreign@example.test', 'Fay', 'Manager', $other, [User::ROLE_RISK_MANAGER]);
        $scope = new Scope('Site de Stuttgart', 'ORGANIZATION', $organization);
        [$framework, $requirements] = self::getContainer()->get(StarterFrameworkCatalog::class)->instantiate('tisax');
        foreach ([$organization, $other, $manager_, $foreign, $scope, $framework, ...$requirements] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($manager_));

        $payload = ['frameworkId' => $framework->getId(), 'scopeId' => $scope->getId(), 'assessorId' => $manager_->getId(), 'assessmentDate' => '2026-11-01', 'status' => 'IN_PROGRESS'];
        $client->jsonRequest('POST', '/api/compliance-assessments', [...$payload, 'tisaxLevel' => 'AL4']);
        self::assertResponseStatusCodeSame(422, 'Only AL2 and AL3 exist.');
        $client->jsonRequest('POST', '/api/compliance-assessments', [...$payload, 'tisaxLevel' => 'AL3', 'tisaxObjectives' => ['INFO_HIGH', 'PROTO_PARTS'], 'siteScope' => 'Site de Stuttgart', 'participantId' => 'ABC123']);
        self::assertResponseStatusCodeSame(201);
        $assessment = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('AL3', $assessment['tisax']['level']);
        self::assertSame(['INFO_HIGH', 'PROTO_PARTS'], $assessment['tisax']['objectives']);

        $client->jsonRequest('PUT', '/api/compliance-assessments/'.$assessment['id'], $payload);
        self::assertResponseIsSuccessful();
        self::assertSame('AL3', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['tisax']['level'], 'An update without TISAX fields keeps the declaration.');

        $client->request('GET', '/api/compliance-assessments/'.$assessment['id'].'/tisax');
        self::assertResponseIsSuccessful();
        $summary = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($summary['declarationComplete']);
        self::assertSame(21, $summary['totals']['total'], 'The 21 leaf sections are assessed; the 9 chapters are groupings.');
        self::assertSame(21, $summary['totals']['incomplete']);
        self::assertFalse($summary['ready']);

        $client->request('GET', '/api/compliance-assessments/'.$assessment['id'].'/results');
        $results = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $leaf = array_values(array_filter($results, static fn (array $row): bool => '1.1' === $row['requirement']['reference']))[0];
        $client->jsonRequest('PUT', '/api/compliance-results/'.$leaf['id'], ['maturityLevel' => 2, 'complianceStatus' => 'PARTIAL', 'targetMaturity' => 3]);
        self::assertResponseIsSuccessful();
        self::assertSame(3, json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['targetMaturity']);
        $client->jsonRequest('PUT', '/api/compliance-results/'.$leaf['id'], ['maturityLevel' => 2, 'complianceStatus' => 'PARTIAL', 'targetMaturity' => 9]);
        self::assertResponseStatusCodeSame(422);

        $client->request('GET', '/api/compliance-assessments/'.$assessment['id'].'/tisax');
        $summary = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $summary['totals']['blocking']);
        $gaps = array_merge(...array_column($summary['modules'], 'gaps'));
        self::assertSame('1.1', $gaps[0]['reference']);

        $client->request('GET', '/api/compliance-assessments/'.$assessment['id'].'/tisax.xlsx');
        self::assertResponseIsSuccessful();
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('PK', (string) $client->getResponse()->getContent());

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($foreign));
        $client->request('GET', '/api/compliance-assessments/'.$assessment['id'].'/tisax');
        self::assertResponseStatusCodeSame(404, 'Another tenant cannot read the TISAX summary.');
    }
}
