<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NotificationControllerTest extends WebTestCase
{
    public function testPaginationFilteringBulkReadAndRecipientIsolation(): void
    {
        $client = self::createClient();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $first = new Organization('First');
        $second = new Organization('Second');
        $actor = new User('reader@example.test', 'Reader', 'One', $first, [User::ROLE_VIEWER]);
        $colleague = new User('colleague@example.test', 'Colleague', 'One', $first, [User::ROLE_VIEWER]);
        $foreignUser = new User('foreign@example.test', 'Reader', 'Two', $second, [User::ROLE_VIEWER]);
        foreach ([$first, $second, $actor, $colleague, $foreignUser] as $entity) {
            $manager->persist($entity);
        }
        for ($index = 0; $index < 55; ++$index) {
            $notification = new Notification($actor, 'RISK_REVIEW', 'Review '.$index, 'Review the risk.', '/risks');
            if ($index < 5) {
                $notification->markRead();
            }
            $manager->persist($notification);
        }
        $other = new Notification($colleague, 'RISK_REVIEW', 'Colleague only', 'Private');
        $foreign = new Notification($foreignUser, 'RISK_REVIEW', 'Foreign only', 'Private');
        $manager->persist($other);
        $manager->persist($foreign);
        $manager->flush();
        $otherId = $other->getId();
        $foreignId = $foreign->getId();
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($actor);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        $client->request('GET', '/api/notifications?limit=25');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Total-Count', '55');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $firstPage = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(25, $firstPage);
        $client->request('GET', '/api/notifications?limit=25&offset=25');
        self::assertResponseIsSuccessful();
        $secondPage = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(25, $secondPage);
        self::assertSame([], array_intersect(array_column($firstPage, 'id'), array_column($secondPage, 'id')));
        $client->request('GET', '/api/notifications?offset=50');
        self::assertCount(5, json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $client->request('GET', '/api/notifications/summary');
        self::assertSame(['total' => 55, 'unread' => 50], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $client->request('GET', '/api/notifications?unreadOnly=true&limit=100');
        self::assertResponseHeaderSame('X-Total-Count', '50');
        $unread = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(50, $unread);
        self::assertSame([false], array_values(array_unique(array_column($unread, 'isRead'))));
        foreach (['limit=101', 'limit=0', 'limit=bad', 'offset=-1', 'unreadOnly=bad'] as $query) {
            $client->request('GET', '/api/notifications?'.$query);
            self::assertResponseStatusCodeSame(422);
        }
        foreach ([$otherId, $foreignId] as $id) {
            $client->request('PUT', '/api/notifications/'.$id.'/read');
            self::assertResponseStatusCodeSame(404);
        }
        $client->request('PUT', '/api/notifications/'.$firstPage[0]['id'].'/read');
        self::assertResponseIsSuccessful();
        $client->request('PUT', '/api/notifications/'.$firstPage[0]['id'].'/read');
        self::assertResponseIsSuccessful();
        $client->request('PUT', '/api/notifications/read-all');
        self::assertResponseIsSuccessful();
        self::assertSame(['updated' => 49], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $client->request('PUT', '/api/notifications/read-all');
        self::assertSame(['updated' => 0], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $client->request('GET', '/api/notifications/summary');
        self::assertSame(['total' => 55, 'unread' => 0], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $client->request('GET', '/api/notifications?unreadOnly=true');
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ([$otherId, $foreignId] as $id) {
            $item = $manager->find(Notification::class, $id);
            self::assertInstanceOf(Notification::class, $item);
            self::assertFalse($item->isRead());
        }
        $moved = $manager->find(User::class, $actor->getId());
        $newOrganization = $manager->find(Organization::class, $second->getId());
        self::assertInstanceOf(User::class, $moved);
        self::assertInstanceOf(Organization::class, $newOrganization);
        $moved->setOrganization($newOrganization);
        $manager->flush();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($moved));
        $client->request('GET', '/api/notifications/summary');
        self::assertSame(['total' => 0, 'unread' => 0], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $client->request('PUT', '/api/notifications/'.$firstPage[0]['id'].'/read');
        self::assertResponseStatusCodeSame(404);
    }
}
