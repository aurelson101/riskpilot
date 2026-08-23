<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\ThirdParty;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TprmGovernanceControllerTest extends WebTestCase
{
    public function testCampaignAttestationAndCrossTenantRejection(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $organization = new Organization('TPRM tenant');
        $foreignOrganization = new Organization('Other TPRM tenant');
        $managerUser = new User('tprm@example.test', 'TPRM', 'Manager', $organization, [User::ROLE_RISK_MANAGER]);
        $foreignUser = new User('foreign-tprm@example.test', 'Foreign', 'Manager', $foreignOrganization, [User::ROLE_RISK_MANAGER]);
        $party = new ThirdParty($organization, $managerUser, 'Critical Cloud', 'CRITICAL');
        $foreignParty = new ThirdParty($foreignOrganization, $foreignUser, 'Foreign Cloud', 'HIGH');
        foreach ([$organization, $foreignOrganization, $managerUser, $foreignUser, $party, $foreignParty] as $entity) $manager->persist($entity);
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($managerUser));

        $client->jsonRequest('POST', '/api/tprm/campaigns', ['title' => 'Réévaluation annuelle', 'thirdPartyIds' => [$party->getId()], 'expiresAt' => '2030-12-31', 'questionsByTier' => ['DEEP' => [['id' => 'q1', 'label' => 'MFA généralisée ?', 'weight' => 10]]]]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['DEEP' => 1], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['details']['segments']);

        $client->jsonRequest('POST', '/api/tprm/attestations', ['thirdPartyId' => $party->getId(), 'name' => 'ISO 27001', 'type' => 'CERTIFICATION', 'sourceReference' => 'vault://suppliers/iso', 'sha256' => str_repeat('c', 64), 'validUntil' => '2030-12-31']);
        self::assertResponseStatusCodeSame(201);
        $client->request('GET', '/api/tprm/governance?limit=1');
        $governance = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $governance['total']);
        self::assertContains(['thirdPartyId' => $party->getId(), 'code' => 'EXIT_PLAN_MISSING'], $governance['alerts']);

        $client->jsonRequest('POST', '/api/tprm/attestations', ['thirdPartyId' => $foreignParty->getId(), 'name' => 'Stolen', 'sha256' => str_repeat('d', 64)]);
        self::assertResponseStatusCodeSame(422);
    }
}
