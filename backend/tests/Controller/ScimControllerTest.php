<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\PlatformIntegration;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ScimControllerTest extends WebTestCase
{
    private const SECRET = 'rp_scim_key_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8';
    private const FOREIGN_SECRET = 'rp_scim_key_zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz';
    private const GROUPS_SECRET = 'rp_grp_key_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8';
    private const READ_ONLY_SECRET = 'rp_read_key_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private int $adminId;
    private int $memberId;
    private int $foreignId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->entityManager);
        $tool->dropSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        $organization = new Organization('Primary');
        $other = new Organization('Other');
        $admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $member = new User('member@example.test', 'Marc', 'Member', $organization, [User::ROLE_RISK_MANAGER]);
        $foreign = new User('foreign@example.test', 'Fay', 'Foreign', $other, [User::ROLE_VIEWER]);
        foreach ([$organization, $other, $admin, $member, $foreign] as $entity) {
            $this->entityManager->persist($entity);
        }
        foreach ([[$organization, self::SECRET, ['scim:write']], [$other, self::FOREIGN_SECRET, ['scim:write']], [$organization, self::READ_ONLY_SECRET, ['risks:read']], [$organization, self::GROUPS_SECRET, ['scim:write', 'scim:groups']]] as [$owner, $secret, $scopes]) {
            $key = new PlatformIntegration($owner, 'API_KEY', 'GENERIC', 'Key', ['scopes' => $scopes], true);
            $key->setCredential($secret);
            $this->entityManager->persist($key);
        }
        $this->entityManager->flush();
        $this->adminId = (int) $admin->getId();
        $this->memberId = (int) $member->getId();
        $this->foreignId = (int) $foreign->getId();
    }

    private function reload(int $id): User
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(User::class)->find($id) ?? throw new \LogicException('User missing.');
    }

    /** @param array<string, mixed>|null $body */
    private function scim(string $method, string $path, ?array $body = null, string $secret = self::SECRET): void
    {
        $this->client->request($method, '/api/scim/v2'.$path, [], [], ['CONTENT_TYPE' => 'application/scim+json', 'HTTP_AUTHORIZATION' => 'Bearer '.$secret], null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return array<string, mixed>
     */
    private function patch(int $id, array $operations): array
    {
        $this->scim('PATCH', '/Users/'.$id, ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => $operations]);

        return $this->json();
    }

    public function testKeyIsRequiredWithTheScimScopeAndBearerOrHeaderIsAccepted(): void
    {
        $this->client->request('GET', '/api/scim/v2/Users');
        self::assertResponseStatusCodeSame(401);
        $this->scim('GET', '/Users', null, 'rp_wrong_key_0000000000000000000000000000');
        self::assertResponseStatusCodeSame(401);
        $this->scim('GET', '/Users', null, self::READ_ONLY_SECRET);
        self::assertResponseStatusCodeSame(403);
        $this->scim('GET', '/ServiceProviderConfig');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/scim+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertTrue($this->json()['patch']['supported']);
        $this->client->request('GET', '/api/scim/v2/Users', [], [], ['HTTP_X_RISKPILOT_KEY' => self::SECRET]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['totalResults'], 'Only the organization of the key is listed.');
    }

    public function testCreateGivesTheViewerRoleOnlyAndRejectsDuplicatesAndInvalidInput(): void
    {
        $this->scim('POST', '/Users', ['userName' => 'New.User@Example.test', 'name' => ['givenName' => 'Nina', 'familyName' => 'New'], 'roles' => [['value' => 'ROLE_ADMIN']], 'active' => true]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->json();
        self::assertSame('new.user@example.test', $created['userName']);
        self::assertTrue($created['active']);
        $user = $this->entityManager->getRepository(User::class)->find((int) $created['id']);
        self::assertSame([User::ROLE_VIEWER], $user?->getRoles(), 'SCIM can never grant a privileged role.');

        $this->scim('POST', '/Users', ['userName' => 'new.user@example.test', 'name' => ['givenName' => 'Nina', 'familyName' => 'New']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('uniqueness', $this->json()['scimType']);
        $this->scim('POST', '/Users', ['userName' => 'foreign@example.test', 'name' => ['givenName' => 'F', 'familyName' => 'F']]);
        self::assertResponseStatusCodeSame(409, 'An email of another organization is not provisioned and not disclosed.');
        foreach ([['userName' => 'not-an-email', 'name' => ['givenName' => 'A', 'familyName' => 'B']], ['userName' => 'a@example.test', 'name' => ['givenName' => '', 'familyName' => 'B']], ['userName' => 'a@example.test', 'name' => ['givenName' => 'A', 'familyName' => 'B'], 'active' => 'maybe']] as $invalid) {
            $this->scim('POST', '/Users', $invalid);
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testFilterAndTenantIsolation(): void
    {
        $this->scim('GET', '/Users?filter='.rawurlencode('userName eq "MEMBER@example.test"'));
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['totalResults']);
        $this->scim('GET', '/Users?filter='.rawurlencode('userName eq "foreign@example.test"'));
        self::assertSame(0, $this->json()['totalResults']);
        $this->scim('GET', '/Users?filter='.rawurlencode('userName co "x"'));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalidFilter', $this->json()['scimType']);
        $this->scim('GET', '/Users/'.$this->foreignId);
        self::assertResponseStatusCodeSame(404);
        $this->scim('PATCH', '/Users/'.$this->memberId, ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]], self::FOREIGN_SECRET);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(User::STATUS_ACTIVE, $this->reload($this->memberId)->getStatus());
    }

    public function testPatchDeactivatesWithoutTouchingRolesOrEmail(): void
    {
        $result = $this->patch($this->memberId, [['op' => 'Replace', 'path' => 'active', 'value' => 'False']]);
        self::assertFalse($result['active'], 'Entra sends the string "False".');
        $member = $this->reload($this->memberId);
        self::assertSame(User::STATUS_INACTIVE, $member->getStatus());
        self::assertContains(User::ROLE_RISK_MANAGER, $member->getRoles());

        $result = $this->patch($this->memberId, [['op' => 'replace', 'value' => ['active' => true, 'name' => ['givenName' => 'Marcus']]]]);
        self::assertTrue($result['active']);
        self::assertSame('Marcus', $result['name']['givenName']);

        foreach (['userName', 'roles', 'emails'] as $path) {
            $this->patch($this->memberId, [['op' => 'replace', 'path' => $path, 'value' => 'x']]);
            self::assertResponseStatusCodeSame(400);
            self::assertSame('mutability', $this->json()['scimType']);
        }
        $this->patch($this->memberId, [['op' => 'remove', 'path' => 'active']]);
        self::assertResponseStatusCodeSame(400);
        $this->scim('PATCH', '/Users/'.$this->memberId, ['Operations' => []]);
        self::assertResponseStatusCodeSame(400, 'The PatchOp schema is mandatory.');
    }

    public function testTheLastActiveAdministratorCannotBeDeactivatedAndLockedAccountsStayLocked(): void
    {
        $this->patch($this->adminId, [['op' => 'replace', 'path' => 'active', 'value' => false]]);
        self::assertResponseStatusCodeSame(403);
        $this->scim('DELETE', '/Users/'.$this->adminId);
        self::assertResponseStatusCodeSame(403);

        $second = new User('admin2@example.test', 'Bob', 'Admin', $this->reload($this->adminId)->getOrganization(), [User::ROLE_ADMIN]);
        $this->entityManager->persist($second);
        $this->entityManager->flush();
        $this->patch($this->adminId, [['op' => 'replace', 'path' => 'active', 'value' => false]]);
        self::assertResponseIsSuccessful();

        $this->reload($this->memberId)->setStatus(User::STATUS_LOCKED);
        $this->entityManager->flush();
        $result = $this->patch($this->memberId, [['op' => 'replace', 'path' => 'active', 'value' => true]]);
        self::assertFalse($result['active']);
        self::assertSame(User::STATUS_LOCKED, $this->reload($this->memberId)->getStatus(), 'The identity provider cannot unlock an account locked by RiskPilot.');
    }

    public function testPutRejectsAnEmailChangeAndDeleteOnlyDeactivates(): void
    {
        $this->scim('PUT', '/Users/'.$this->memberId, ['userName' => 'someone.else@example.test', 'name' => ['givenName' => 'M', 'familyName' => 'M'], 'active' => true]);
        self::assertResponseStatusCodeSame(400);
        $this->scim('PUT', '/Users/'.$this->memberId, ['userName' => 'member@example.test', 'name' => ['givenName' => 'Marco', 'familyName' => 'Member'], 'active' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame('Marco', $this->json()['name']['givenName']);

        $this->scim('DELETE', '/Users/'.$this->memberId);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(User::STATUS_INACTIVE, $this->reload($this->memberId)->getStatus(), 'The account is kept for accountability.');
        $this->scim('GET', '/Users/'.$this->memberId);
        self::assertFalse($this->json()['active']);
    }

    public function testGroupsNeedTheirOwnScopeAndAreFixedToAssignableRoles(): void
    {
        $this->scim('GET', '/Groups');
        self::assertResponseStatusCodeSame(403, 'scim:write alone cannot touch roles.');
        $this->scim('GET', '/Groups', null, self::GROUPS_SECRET);
        self::assertResponseIsSuccessful();
        self::assertSame(['ROLE_RISK_MANAGER', 'ROLE_ACTION_OWNER', 'ROLE_AUDITOR'], array_column($this->json()['Resources'], 'id'));
        self::assertSame([(string) $this->memberId], array_column($this->json()['Resources'][0]['members'], 'value'));
        $this->scim('GET', '/Groups/ROLE_ADMIN', null, self::GROUPS_SECRET);
        self::assertResponseStatusCodeSame(404, 'Administration roles are not exposed as groups.');
        $this->scim('POST', '/Groups', ['displayName' => 'x'], self::GROUPS_SECRET);
        self::assertResponseStatusCodeSame(403);
        $this->scim('DELETE', '/Groups/ROLE_AUDITOR', null, self::GROUPS_SECRET);
        self::assertResponseStatusCodeSame(403);
    }

    public function testGroupMembershipChangesRolesWithinTheOrganizationOnly(): void
    {
        $patch = fn (string $role, array $operations, string $secret = self::GROUPS_SECRET) => $this->scim('PATCH', '/Groups/'.$role, ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => $operations], $secret);
        $patch('ROLE_AUDITOR', [['op' => 'add', 'path' => 'members', 'value' => [['value' => (string) $this->memberId]]]]);
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing([User::ROLE_RISK_MANAGER, User::ROLE_AUDITOR, User::ROLE_VIEWER], $this->reload($this->memberId)->getRoles());

        $patch('ROLE_AUDITOR', [['op' => 'remove', 'path' => 'members[value eq "'.$this->memberId.'"]']]);
        self::assertResponseIsSuccessful();
        self::assertNotContains(User::ROLE_AUDITOR, $this->reload($this->memberId)->getRoles());

        $patch('ROLE_AUDITOR', [['op' => 'add', 'path' => 'members', 'value' => [['value' => (string) $this->foreignId]]]]);
        self::assertResponseStatusCodeSame(400, 'A user of another organization cannot be added.');
        $patch('ROLE_AUDITOR', [['op' => 'replace', 'path' => 'displayName', 'value' => 'x']]);
        self::assertResponseStatusCodeSame(400);

        $this->scim('PUT', '/Groups/ROLE_RISK_MANAGER', ['members' => []], self::GROUPS_SECRET);
        self::assertResponseIsSuccessful();
        self::assertNotContains(User::ROLE_RISK_MANAGER, $this->reload($this->memberId)->getRoles());
        self::assertContains(User::ROLE_ADMIN, $this->reload($this->adminId)->getRoles(), 'Administration roles are never touched by groups.');

        $patch('ROLE_AUDITOR', [['op' => 'add', 'path' => 'members', 'value' => [['value' => (string) $this->memberId]]]], self::FOREIGN_SECRET);
        self::assertResponseStatusCodeSame(403, 'Another organization key without the groups scope is refused.');
    }
}
