<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EmailSettingsValidationControllerTest extends WebTestCase
{
    public function testInvalidEmailSettingsAreRejectedBeforeSaving(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $org = new Organization('Mail validation');
        $user = new User('mail@example.test', 'Mail', 'Admin', $org, [User::ROLE_ADMIN]);
        $em->persist($org);
        $em->persist($user);
        $em->flush();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($user));
        $valid = ['provider' => 'CUSTOM', 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'mail-user', 'password' => 'test-only-password', 'senderEmail' => 'sender@example.test', 'senderName' => 'RiskPilot', 'enabled' => false];
        foreach ([['provider' => ['CUSTOM']], ['host' => ['smtp.example.test']], ['enabled' => 'false'], ['port' => '587'],
            ['password' => str_repeat('x', 4097)], ['oauthClientSecret' => str_repeat('x', 4097)],
            ['senderEmail' => str_repeat('a', 181).'@example.test'], ['replyTo' => str_repeat('a', 181).'@example.test'],
            ['username' => "mail\x00user"], ['senderName' => "Mail\x01Admin"], ['oauthClientId' => "client\nId"],
        ] as $override) {
            $client->jsonRequest('PUT', '/api/settings/email', [...$valid, ...$override]);
            self::assertResponseStatusCodeSame(422);
        }
        $client->jsonRequest('PUT', '/api/settings/email', $valid);
        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($body['passwordConfigured']);
        self::assertStringNotContainsString('test-only-password', $client->getResponse()->getContent());
        $client->request('POST', '/api/settings/email/oauth/custom/authorize');
        self::assertResponseStatusCodeSame(422);
        $client->setServerParameter('HTTP_AUTHORIZATION', '');
        foreach (['', 'bad-state', str_repeat('a', 64).'\n'] as $state) {
            $client->request('GET', '/api/settings/email/oauth/microsoft_365/callback?state='.rawurlencode($state));
            self::assertResponseRedirects('http://localhost:8080/administration/email-settings?oauth=error');
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
            self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        }
    }
}
