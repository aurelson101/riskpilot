<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\OidcClient;
use App\Application\OidcDiscoveryValidator;
use App\Entity\Organization;
use App\Entity\User;
use App\Security\OidcIdTokenVerifier;
use App\Security\SecretCipher;
use App\Tests\Security\OidcTestTokens;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SsoRoleMappingTest extends WebTestCase
{
    public function testRolesFollowProviderGroupsAdministrationIsProtectedAndLogoutUrlIsReturned(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $container = self::getContainer();
        foreach (['127.0.0.1'] as $address) {
            foreach (['sso-start|', 'sso-complete|'] as $prefix) {
                $container->get('limiter.login')->create($prefix.$address)->reset();
            }
        }
        $manager = $container->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $organization = new Organization('SSO roles');
        $admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $member = new User('member@example.test', 'Marc', 'Member', $organization, [User::ROLE_VIEWER]);
        foreach ([$organization, $admin, $member] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $adminId = (int) $admin->getId();
        $memberId = (int) $member->getId();

        $nonce = '';
        $claims = [];
        $endSession = 'https://accounts.google.com/logout';
        $tokens = new OidcTestTokens();
        $http = new MockHttpClient(static function (string $method, string $url) use (&$nonce, &$claims, &$endSession, $tokens): MockResponse {
            if (str_contains($url, '.well-known')) {
                return new MockResponse(json_encode(['issuer' => 'https://accounts.google.com', 'authorization_endpoint' => 'https://accounts.google.com/authorize', 'token_endpoint' => 'https://oauth2.googleapis.com/token', 'jwks_uri' => 'https://www.googleapis.com/keys', 'response_types_supported' => ['code'], 'scopes_supported' => ['openid'], 'id_token_signing_alg_values_supported' => ['RS256'], 'token_endpoint_auth_methods_supported' => ['client_secret_post'], 'end_session_endpoint' => $endSession], JSON_THROW_ON_ERROR));
            }
            if ('POST' === $method) {
                return new MockResponse(json_encode(['id_token' => $tokens->token(['nonce' => $nonce] + $claims)], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode($tokens->jwks, JSON_THROW_ON_ERROR));
        });
        $discovery = new OidcDiscoveryValidator($http);
        $container->set(OidcDiscoveryValidator::class, $discovery);
        $container->set(OidcClient::class, new OidcClient($http, $discovery, new OidcIdTokenVerifier(), $container->get(SecretCipher::class)));

        $adminHeader = 'Bearer '.$container->get(JWTTokenManagerInterface::class)->create($admin);
        $client->setServerParameter('HTTP_AUTHORIZATION', $adminHeader);
        $base = ['issuer' => 'https://accounts.google.com', 'clientId' => 'test-client', 'accountMappings' => [['userId' => $memberId, 'subject' => 'stable-user'], ['userId' => $adminId, 'subject' => 'admin-sub']]];
        $mappings = [['group' => 'rp-auditors', 'role' => 'ROLE_AUDITOR'], ['group' => 'rp-owners', 'role' => 'ROLE_ACTION_OWNER']];
        foreach ([
            ['roleMappings' => $mappings],
            ['roleClaim' => 'groups', 'roleMappings' => [['group' => 'x', 'role' => 'ROLE_ADMIN']]],
            ['roleClaim' => 'groups', 'roleMappings' => [['group' => 'x', 'role' => 'ROLE_SUPER_ADMIN']]],
            ['roleClaim' => 'bad claim!', 'roleMappings' => $mappings],
            ['roleClaim' => 'groups', 'roleMappings' => [['group' => '', 'role' => 'ROLE_AUDITOR']]],
        ] as $invalid) {
            $client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'OIDC', 'provider' => 'GOOGLE_WORKSPACE', 'name' => 'Google', 'configuration' => $base + $invalid, 'credential' => 'test-only-secret', 'enabled' => false]);
            self::assertResponseStatusCodeSame(422, json_encode($invalid, JSON_THROW_ON_ERROR));
        }
        $client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'OIDC', 'provider' => 'GOOGLE_WORKSPACE', 'name' => 'Google', 'configuration' => $base + ['roleClaim' => 'groups', 'roleMappings' => $mappings], 'credential' => 'test-only-secret', 'enabled' => true]);
        self::assertResponseStatusCodeSame(201);
        $id = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        $login = function (array $tokenClaims) use ($client, $container, $id, &$nonce, &$claims): array {
            foreach (['sso-start|', 'sso-complete|'] as $prefix) {
                $container->get('limiter.login')->create($prefix.'127.0.0.1')->reset();
            }
            $claims = $tokenClaims;
            $client->setServerParameter('HTTP_AUTHORIZATION', '');
            $client->request('GET', '/api/auth/sso/'.$id.'/start');
            parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $parameters);
            $nonce = $parameters['nonce'];
            $client->request('GET', '/api/auth/sso/callback?state='.$parameters['state'].'&code=test-code');
            self::assertResponseRedirects('http://localhost:8080/login?sso=complete');
            $client->jsonRequest('POST', '/api/auth/sso/complete', []);
            self::assertResponseIsSuccessful();

            return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        };
        $roles = static fn (int $userId): array => $manager->getRepository(User::class)->find($userId)?->getRoles() ?? [];

        $session = $login(['groups' => ['rp-auditors', 'rp-owners', 'unrelated']]);
        self::assertEqualsCanonicalizing([User::ROLE_AUDITOR, User::ROLE_ACTION_OWNER, User::ROLE_VIEWER], $roles($memberId));
        $url = (string) $session['sso']['logoutUrl'];
        self::assertStringStartsWith('https://accounts.google.com/logout?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $logout);
        self::assertSame('http://localhost:8080/login', $logout['post_logout_redirect_uri']);
        self::assertSame('test-client', $logout['client_id']);
        self::assertSame(3, substr_count($logout['id_token_hint'], '.') + 1, 'The id_token is passed as the logout hint.');

        $login(['groups' => ['rp-owners']]);
        self::assertEqualsCanonicalizing([User::ROLE_ACTION_OWNER, User::ROLE_VIEWER], $roles($memberId), 'Roles follow the groups at every login.');
        $login([]);
        self::assertEqualsCanonicalizing([User::ROLE_ACTION_OWNER, User::ROLE_VIEWER], $roles($memberId), 'A token without the claim leaves roles untouched.');
        $login(['groups' => []]);
        self::assertSame([User::ROLE_VIEWER], $roles($memberId), 'An empty group list removes the mapped roles.');

        $adminLogin = $login(['sub' => 'admin-sub', 'groups' => []]);
        self::assertNotEmpty($adminLogin['token']);
        self::assertContains(User::ROLE_ADMIN, $roles($adminId), 'The identity provider never removes administration roles.');
        $login(['sub' => 'admin-sub', 'groups' => ['rp-auditors']]);
        self::assertEqualsCanonicalizing([User::ROLE_ADMIN, User::ROLE_AUDITOR, User::ROLE_VIEWER], $roles($adminId));

        $endSession = 'https://evil.example.test/logout';
        $untrusted = $login(['groups' => ['rp-auditors']]);
        self::assertArrayNotHasKey('sso', $untrusted, 'A logout endpoint outside the provider hosts is never offered.');
    }
}
