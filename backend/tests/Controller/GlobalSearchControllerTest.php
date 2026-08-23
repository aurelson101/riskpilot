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

final class GlobalSearchControllerTest extends WebTestCase
{
    public function testSearchIsTenantScopedAndPaginated(): void
    {
        $client = self::createClient();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $organization = new Organization('Primary');
        $foreignOrganization = new Organization('Foreign');
        $actor = new User('search@example.test', 'Search', 'User', $organization, [User::ROLE_VIEWER]);
        $foreign = new User('foreign@example.test', 'Foreign', 'User', $foreignOrganization, [User::ROLE_VIEWER]);
        $visible = new ThirdParty($organization, $actor, 'Cloud Alpha', 'HIGH');
        $hidden = new ThirdParty($foreignOrganization, $foreign, 'Cloud Alpha Secret', 'CRITICAL');
        foreach ([$organization, $foreignOrganization, $actor, $foreign, $visible, $hidden] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($actor);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
        $client->request('GET', '/api/search?q=cloud&page=1&limit=1');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['total']);
        self::assertSame(1, $payload['pages']);
        self::assertSame('Cloud Alpha', $payload['items'][0]['title']);

        $client->request('GET', '/api/search?q=x');
        self::assertResponseStatusCodeSame(422);
    }
}
