<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\RiskAnalysis;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EbiosRbacControllerTest extends WebTestCase
{
    public function testWorkshopWorkflowAndConfigurablePermission(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $organization = new Organization('Tenant');
        $admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $auditor = new User('auditor@example.test', 'Aude', 'Audit', $organization, [User::ROLE_AUDITOR]);
        $analysis = new RiskAnalysis($organization, $admin, 'ebios.demo', 1, 'EBIOS_RM', 'EBIOS Demo');
        foreach ([$organization, $admin, $auditor, $analysis] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($admin));

        $client->jsonRequest('PUT', '/api/v1/ebios/analyses/'.$analysis->getId().'/workshops/1', ['payload' => ['context' => 'Périmètre', 'businessValues' => ['ERP'], 'supportingAssets' => ['Serveur'], 'dreadedEvents' => ['Indisponibilité'], 'securityBaseline' => ['MFA']]]);
        self::assertResponseIsSuccessful();
        self::assertSame('READY', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['status']);

        $client->request('POST', '/api/v1/ebios/analyses/'.$analysis->getId().'/workshops/1/validate');
        self::assertResponseStatusCodeSame(422);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($auditor));
        $client->request('POST', '/api/v1/ebios/analyses/'.$analysis->getId().'/workshops/1/validate');
        self::assertResponseIsSuccessful();
        self::assertSame('VALIDATED', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['status']);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($admin));
        $client->request('GET', '/api/settings/rbac');
        self::assertResponseIsSuccessful();
        $matrix = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $matrix['roles'][User::ROLE_AUDITOR] = ['risk.read'];
        $client->jsonRequest('PUT', '/api/settings/rbac', ['roles' => $matrix['roles']]);
        self::assertResponseIsSuccessful();

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($auditor));
        $client->request('GET', '/api/v1/ebios/analyses');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/settings/rbac');
        self::assertResponseStatusCodeSame(403);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($admin));
        $client->request('GET', '/api/settings/rbac');
        self::assertResponseIsSuccessful();
        $matrix = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $matrix['roles'][User::ROLE_ADMIN] = ['risk.read'];
        $client->jsonRequest('PUT', '/api/settings/rbac', ['roles' => $matrix['roles']]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/settings/rbac');
        self::assertResponseStatusCodeSame(403);
    }
}
