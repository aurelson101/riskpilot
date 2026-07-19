<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthSessionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $organization = new Organization('Sessions');
        $this->user = new User('session@example.test', 'Alice', 'Session', $organization, [User::ROLE_ADMIN]);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user->setPassword($hasher->hashPassword($this->user, 'StrongPassword123!'));
        $this->entityManager->persist($organization);
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    public function testLoginRefreshAndLogoutRevokeServerSession(): void
    {
        $this->json('POST', '/api/auth/login', ['email' => 'session@example.test', 'password' => 'StrongPassword123!']);
        self::assertResponseIsSuccessful();
        $firstToken = $this->payload()['token'];
        self::assertNotEmpty($firstToken);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$firstToken);
        $this->client->request('GET', '/api/me/sessions');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payloadList()[0]['current']);

        $this->json('POST', '/api/auth/refresh');
        self::assertResponseIsSuccessful();
        $rotatedAccessToken = $this->payload()['token'];
        self::assertNotSame($firstToken, $rotatedAccessToken);

        $this->json('POST', '/api/auth/logout');
        self::assertResponseStatusCodeSame(204);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$rotatedAccessToken);
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testPasswordResetIsSingleUseAndRevokesSessions(): void
    {
        $this->json('POST', '/api/auth/login', ['email' => 'session@example.test', 'password' => 'StrongPassword123!']);
        $accessToken = $this->payload()['token'];
        $rawResetToken = 'known-reset-token-with-enough-entropy-for-a-test';
        $this->entityManager->persist(new PasswordResetToken($this->user, hash('sha256', $rawResetToken)));
        $this->entityManager->flush();

        $this->json('POST', '/api/auth/reset-password', ['token' => $rawResetToken, 'password' => 'NewStrongPassword456!']);
        self::assertResponseIsSuccessful();
        $this->json('POST', '/api/auth/reset-password', ['token' => $rawResetToken, 'password' => 'AnotherPassword789!']);
        self::assertResponseStatusCodeSame(422);

        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$accessToken);
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->json('POST', '/api/auth/login', ['email' => 'session@example.test', 'password' => 'NewStrongPassword456!']);
        self::assertResponseIsSuccessful();
    }

    /** @param array<string, mixed> $data */
    private function json(string $method, string $uri, array $data = []): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function payloadList(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
