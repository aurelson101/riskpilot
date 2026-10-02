<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ActionPlan;
use App\Entity\Asset;
use App\Entity\IsmsDocument;
use App\Entity\IsmsDocumentAcl;
use App\Entity\Organization;
use App\Entity\RiskScenario;
use App\Entity\Scope;
use App\Entity\SecurityControl;
use App\Entity\ThirdParty;
use App\Entity\Threat;
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

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $actor = $manager->find(User::class, $actor->getId());
        $foreign = $manager->find(User::class, $foreign->getId());
        self::assertInstanceOf(User::class, $actor);
        self::assertInstanceOf(User::class, $foreign);
        $organization = $actor->getOrganization();
        $owner = new User('owner@example.test', 'Owner', 'User', $organization, [User::ROLE_VIEWER]);
        $scope = new Scope('Search scope', 'TECHNICAL', $organization);
        $asset = new Asset('Search server', 'SERVER', $scope, $organization);
        $threat = new Threat('Search threat', 'TECHNICAL', $organization);
        $risk = new RiskScenario('Cloud risk', $organization, $scope, $asset, $threat, $actor);
        $action = new ActionPlan('Cloud action', $organization, $risk, $actor, new \DateTimeImmutable('+10 days'));
        $overdue = new ActionPlan('Cloud overdue', $organization, $risk, $actor, new \DateTimeImmutable('-2 days'));
        $control = new SecurityControl('Cloud control', 'TECHNICAL', $organization);
        $private = new IsmsDocument($organization, $owner, 'Cloud private', 'POLICY', 'Secret');
        $private->updateMetadata('Cloud private', 'POLICY', 'DRAFT', 'INTERNAL', 'RESTRICTED', $owner);
        $shared = new IsmsDocument($organization, $owner, 'Cloud shared', 'POLICY', 'Shared');
        $shared->updateMetadata('Cloud shared', 'POLICY', 'DRAFT', 'INTERNAL', 'RESTRICTED', $owner);
        $acl = new IsmsDocumentAcl($shared, $actor, 'READ');
        $literal = new ThirdParty($organization, $actor, 'Cloud 100%_!', 'HIGH');
        foreach ([$owner, $scope, $asset, $threat, $risk, $action, $overdue, $control, $private, $shared, $acl, $literal] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $client->request('GET', '/api/search?q=cloud&limit=2');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(7, $payload['total']);
        self::assertCount(2, $payload['items']);
        self::assertSame(1, $payload['counts']['DOCUMENT']);
        $client->request('GET', '/api/search?q=cloud&type=DOCUMENT');
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Cloud shared', $payload['items'][0]['title']);
        self::assertSame(1, $payload['total']);
        $client->request('GET', '/api/search?q=cloud&type=ACTION&sort=title');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $payload['total']);
        self::assertSame('Cloud action', $payload['items'][0]['title']);
        $client->request('GET', '/api/search?q='.urlencode('100%_!'));
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['total']);
        $client->request('GET', '/api/search?q=cloud&page=1000&limit=2');
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(4, $payload['page']);
        self::assertCount(1, $payload['items']);
        foreach (['q='.str_repeat('x', 161), 'q=cloud&page=-1', 'q=cloud&page=1001', 'q=cloud&page=abc', 'q=cloud&limit=51', 'q=cloud&type=INVALID', 'q=cloud&sort=invalid'] as $query) {
            $client->request('GET', '/api/search?'.$query);
            self::assertResponseStatusCodeSame(422);
        }
        $client->request('GET', '/api/dashboard');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['summary']['dueActions']);
        self::assertSame(1, $payload['summary']['overdueActions']);
        self::assertArrayHasKey('level', $payload['topRisks'][0]);
        foreach (['risks', 'actions'] as $resource) {
            $client->request('GET', '/api/exports/'.$resource.'.csv');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Cloud', (string) $client->getResponse()->getContent());
        }
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($foreign));
        $client->request('GET', '/api/search?q=cloud');
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['total']);
        self::assertSame('Cloud Alpha Secret', $payload['items'][0]['title']);
        $limiter = self::getContainer()->get('limiter.global_search');
        $limiter->create($foreign->getOrganization()->getId().':'.$foreign->getId())->consume(59);
        $client->request('GET', '/api/search?q=cloud');
        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
    }
}
