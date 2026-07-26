<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ActionCustomFieldControllerTest extends WebTestCase
{
    public function testCreateAcceptsSingleCharacterKeyAndRejectsDuplicateCleanly(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $organization = new Organization('Direction');
        $admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $manager->persist($organization);
        $manager->persist($admin);
        $manager->flush();

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($admin));
        $payload = ['key' => 'a', 'label' => 'Approbation', 'type' => 'TEXT'];

        $client->jsonRequest('POST', '/api/action-custom-fields', $payload);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('POST', '/api/action-custom-fields', $payload);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'FIELD_KEY_EXISTS',
            json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['code'],
        );
    }
}
