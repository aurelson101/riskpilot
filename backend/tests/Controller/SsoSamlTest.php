<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Tests\Support\SamlTestIdp;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SsoSamlTest extends WebTestCase
{
    private const ACS_PATH = '/api/auth/sso/saml/%d/acs';

    private KernelBrowser $client;
    private SamlTestIdp $idp;
    private int $integrationId;
    private int $memberId;
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->manager = $container->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->manager);
        $tool->dropSchema($this->manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($this->manager->getMetadataFactory()->getAllMetadata());
        $organization = new Organization('SAML');
        $admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $member = new User('member@example.test', 'Marc', 'Member', $organization, [User::ROLE_VIEWER]);
        $linked = new User('linked@example.test', 'Lea', 'Linked', $organization, [User::ROLE_VIEWER]);
        $linked->setScimExternalId('scim-lea');
        foreach ([$organization, $admin, $member, $linked] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();
        $this->memberId = (int) $member->getId();
        $this->idp = new SamlTestIdp();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$container->get(JWTTokenManagerInterface::class)->create($admin));
        $configuration = [
            'issuer' => SamlTestIdp::ENTITY_ID, 'ssoUrl' => 'https://idp.example.test/realms/test/protocol/saml', 'idpCertificate' => $this->idp->certificate,
            'accountMappings' => [['userId' => $this->memberId, 'subject' => 'user-1']],
            'roleClaim' => 'http://schemas.xmlsoap.org/claims/Group', 'roleMappings' => [['group' => 'auditors', 'role' => 'ROLE_AUDITOR']],
        ];
        $this->client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'SAML', 'provider' => 'KEYCLOAK', 'name' => 'Keycloak SAML', 'configuration' => $configuration, 'enabled' => true]);
        self::assertResponseStatusCodeSame(201);
        $this->integrationId = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
    }

    private function acs(): string
    {
        return 'http://localhost:8080'.sprintf(self::ACS_PATH, $this->integrationId);
    }

    private function sp(): string
    {
        return 'http://localhost:8080/api/auth/sso/saml/'.$this->integrationId.'/metadata';
    }

    /** @return array{state: string, requestId: string} */
    private function start(): array
    {
        self::getContainer()->get('limiter.login')->create('sso-start|127.0.0.1')->reset();
        $this->client->request('GET', '/api/auth/sso/'.$this->integrationId.'/start');
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://idp.example.test/realms/test/protocol/saml?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return ['state' => $query['RelayState'], 'requestId' => SamlTestIdp::requestId($location)];
    }

    /** @param array<string, mixed> $options */
    private function post(array $flow, array $options = [], ?string $state = null): void
    {
        $this->client->request('POST', sprintf(self::ACS_PATH, $this->integrationId), ['SAMLResponse' => $this->idp->response($this->acs(), $this->sp(), $flow['requestId'], $options), 'RelayState' => $state ?? $flow['state']]);
    }

    /** @param array<string, mixed> $options */
    private function login(array $options = []): array
    {
        $flow = $this->start();
        $this->post($flow, $options);
        self::assertResponseRedirects('http://localhost:8080/login?sso=complete');
        self::getContainer()->get('limiter.login')->create('sso-complete|127.0.0.1')->reset();
        $this->client->jsonRequest('POST', '/api/auth/sso/complete', []);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertRejected(array $flow, array $options = [], ?string $state = null): void
    {
        $this->post($flow, $options, $state);
        self::assertResponseRedirects('http://localhost:8080/login?sso=error');
    }

    public function testSignedAssertionSignsInAndMapsGroupsFromAttributesWithUrlNames(): void
    {
        $session = $this->login(['attributes' => ['http://schemas.xmlsoap.org/claims/Group' => ['auditors', 'other']]]);
        self::assertNotEmpty($session['token']);
        self::assertEqualsCanonicalizing([User::ROLE_AUDITOR, User::ROLE_VIEWER], $this->manager->getRepository(User::class)->find($this->memberId)->getRoles());
        self::assertArrayNotHasKey('sso', $session, 'SAML single logout is not offered.');
    }

    public function testSignedResponseAloneIsAccepted(): void
    {
        self::assertNotEmpty($this->login(['sign' => 'response'])['token']);
    }

    public function testRejectsUnsignedTamperedForeignAndWeakResponses(): void
    {
        $flow = $this->start();
        $this->assertRejected($flow, ['sign' => 'none']);
        $flow = $this->start();
        $this->assertRejected($flow, ['tamper' => true]);
        $flow = $this->start();
        $this->assertRejected($flow, ['audience' => 'https://other.example.test/sp']);
        $flow = $this->start();
        $this->assertRejected($flow, ['destination' => 'https://evil.example.test/acs']);
        $flow = $this->start();
        $this->assertRejected($flow, ['notOnOrAfter' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i:s\Z')]);
        $flow = $this->start();
        $this->assertRejected($flow, ['issuer' => 'https://evil.example.test/idp']);
        $flow = $this->start();
        $this->assertRejected($flow, ['algorithm' => 'sha1']);
        $flow = $this->start();
        $this->assertRejected($flow, ['subject' => 'unknown-person']);
        $flow = $this->start();
        $this->assertRejected($flow, ['inResponseTo' => '_another_request']);
        $flow = $this->start();
        $this->assertRejected($flow, ['inResponseTo' => null]);
    }

    public function testRejectsReplayMissingStateAndForeignSigner(): void
    {
        $flow = $this->start();
        $this->post($flow);
        self::assertResponseRedirects('http://localhost:8080/login?sso=complete');
        $this->post($flow);
        self::assertResponseRedirects('http://localhost:8080/login?sso=error', null, 'A response cannot be replayed.');

        $flow = $this->start();
        $this->assertRejected($flow, [], str_repeat('a', 64));
        $this->client->getCookieJar()->clear();
        $this->post($flow);
        self::assertResponseRedirects('http://localhost:8080/login?sso=error', null, 'The browser binding cookie is required.');

        $flow = $this->start();
        $stranger = new SamlTestIdp();
        $this->client->request('POST', sprintf(self::ACS_PATH, $this->integrationId), ['SAMLResponse' => $stranger->response($this->acs(), $this->sp(), $flow['requestId']), 'RelayState' => $flow['state']]);
        self::assertResponseRedirects('http://localhost:8080/login?sso=error', null, 'A response signed with another key is refused.');
    }

    public function testScimExternalIdLinksAccountsWhenEnabled(): void
    {
        $flow = $this->start();
        $this->assertRejected($flow, ['subject' => 'scim-lea']);

        $admin = $this->manager->getRepository(User::class)->findOneBy(['email' => 'admin@example.test']);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($admin));
        $this->client->request('GET', '/api/v1/integrations');
        $items = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['items'];
        $configuration = $items[0]['configuration'];
        $this->client->jsonRequest('PUT', '/api/v1/integrations/'.$this->integrationId, ['configuration' => $configuration + ['linkScimExternalId' => true]]);
        self::assertResponseIsSuccessful();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        self::assertNotEmpty($this->login(['subject' => 'scim-lea'])['token']);
        $this->assertRejected($this->start(), ['subject' => 'nobody']);
    }

    public function testMetadataIsPublicAndConfigurationIsValidated(): void
    {
        $this->client->request('GET', '/api/auth/sso/saml/'.$this->integrationId.'/metadata');
        self::assertResponseIsSuccessful();
        $xml = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->sp(), $xml);
        self::assertStringContainsString($this->acs(), $xml);
        $this->client->request('GET', '/api/auth/sso/saml/999999/metadata');
        self::assertResponseStatusCodeSame(404);

        $admin = $this->manager->getRepository(User::class)->findOneBy(['email' => 'admin@example.test']);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($admin));
        $valid = ['issuer' => SamlTestIdp::ENTITY_ID, 'ssoUrl' => 'https://idp.example.test/sso', 'idpCertificate' => $this->idp->certificate, 'accountMappings' => []];
        foreach ([
            ['ssoUrl' => 'http://idp.example.test/sso'],
            ['ssoUrl' => 'https://127.0.0.1/sso'],
            ['idpCertificate' => 'not a certificate'],
            ['idpCertificate' => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----"],
            ['issuer' => ''],
        ] as $invalid) {
            $this->client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'SAML', 'provider' => 'KEYCLOAK', 'name' => 'Invalid', 'configuration' => $invalid + $valid, 'enabled' => false]);
            self::assertResponseStatusCodeSame(422, json_encode($invalid, JSON_THROW_ON_ERROR));
        }
        $this->client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'SAML', 'provider' => 'KEYCLOAK', 'name' => 'Needs accounts', 'configuration' => $valid, 'enabled' => true]);
        self::assertResponseStatusCodeSame(422, 'Enabling requires an account association or SCIM linking.');
        $this->client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'SAML', 'provider' => 'KEYCLOAK', 'name' => 'Linked', 'configuration' => $valid + ['linkScimExternalId' => true], 'enabled' => true]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testProvidersListExposesTheProtocol(): void
    {
        $this->client->request('GET', '/api/auth/sso/providers');
        $items = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['items'];
        self::assertSame('SAML', $items[0]['protocol']);
    }

    private function adminHeader(): void
    {
        $admin = $this->manager->getRepository(User::class)->findOneBy(['email' => 'admin@example.test']);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($admin));
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        $this->adminHeader();
        $this->client->request('GET', '/api/v1/integrations');

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['items'][0]['configuration'];
    }

    public function testEncryptedAssertionsAreRequiredWhenEnabledAndTheKeyNeverLeavesTheServer(): void
    {
        $configuration = $this->configuration();
        $this->client->jsonRequest('PUT', '/api/v1/integrations/'.$this->integrationId, ['configuration' => $configuration + ['encryptAssertions' => true]]);
        self::assertResponseIsSuccessful();
        $saved = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $certificate = $saved['configuration']['spCertificate'];
        self::assertStringContainsString('BEGIN CERTIFICATE', $certificate);
        self::assertStringNotContainsString('PRIVATE KEY', json_encode($saved, JSON_THROW_ON_ERROR), 'The private key is never returned.');
        $this->client->jsonRequest('PUT', '/api/v1/integrations/'.$this->integrationId, ['configuration' => $saved['configuration']]);
        self::assertSame($certificate, json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['configuration']['spCertificate'], 'The key pair is stable across saves.');
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');

        $this->client->request('GET', '/api/auth/sso/saml/'.$this->integrationId.'/metadata');
        self::assertStringContainsString('KeyDescriptor', (string) $this->client->getResponse()->getContent());

        $flow = $this->start();
        $this->assertRejected($flow, []);

        $flow = $this->start();
        $this->post($flow, ['encryptFor' => $certificate]);
        self::assertResponseRedirects('http://localhost:8080/login?sso=complete');
        self::getContainer()->get('limiter.login')->create('sso-complete|127.0.0.1')->reset();
        $this->client->jsonRequest('POST', '/api/auth/sso/complete', []);
        self::assertResponseIsSuccessful();

        $flow = $this->start();
        $stranger = new SamlTestIdp();
        $this->post($flow, ['encryptFor' => $stranger->certificate]);
        self::assertResponseRedirects('http://localhost:8080/login?sso=error', null, 'An assertion encrypted for another key cannot be read.');

        $this->adminHeader();
        $this->client->jsonRequest('PUT', '/api/v1/integrations/'.$this->integrationId, ['configuration' => $configuration]);
        self::assertArrayNotHasKey('spCertificate', json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['configuration']);
    }

    public function testSingleLogoutIsOfferedAfterSignInAndProviderInitiatedLogoutRequiresASignature(): void
    {
        $configuration = $this->configuration();
        $this->client->jsonRequest('PUT', '/api/v1/integrations/'.$this->integrationId, ['configuration' => $configuration + ['sloUrl' => 'https://idp.example.test/realms/test/protocol/saml/logout']]);
        self::assertResponseIsSuccessful();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');

        $session = $this->login(['subject' => 'user-1']);
        $url = (string) ($session['sso']['logoutUrl'] ?? '');
        self::assertStringStartsWith('https://idp.example.test/realms/test/protocol/saml/logout?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $xml = (string) gzinflate((string) base64_decode($query['SAMLRequest'], true));
        self::assertStringContainsString('>user-1<', $xml);
        self::assertSame('http://localhost:8080/login', $query['RelayState']);

        // Provider-initiated logout: unsigned requests are ignored, signed ones revoke the sessions.
        $sls = '/api/auth/sso/saml/'.$this->integrationId.'/sls';
        $slsUrl = 'http://localhost:8080'.$sls;
        $before = $this->manager->getRepository(\App\Entity\AuthSession::class)->count(['revokedAt' => null]);
        self::assertGreaterThan(0, $before);
        $this->client->request('GET', $sls.'?'.http_build_query($this->idp->logoutRequest($slsUrl, $this->sp(), 'user-1', false), '', '&', PHP_QUERY_RFC3986));
        self::assertResponseRedirects('http://localhost:8080/login');
        self::assertSame($before, $this->manager->getRepository(\App\Entity\AuthSession::class)->count(['revokedAt' => null]), 'An unsigned logout request changes nothing.');

        $this->client->request('GET', $sls.'?'.http_build_query($this->idp->logoutRequest($slsUrl, $this->sp(), 'user-1', true), '', '&', PHP_QUERY_RFC3986));
        self::assertResponseStatusCodeSame(302);
        self::assertStringStartsWith('https://idp.example.test/realms/test/protocol/saml/logout?SAMLResponse=', (string) $this->client->getResponse()->headers->get('Location'));
        $this->manager->clear();
        self::assertSame(0, $this->manager->getRepository(\App\Entity\AuthSession::class)->count(['revokedAt' => null]), 'Sessions of the account are revoked.');
    }
}
