<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\OidcClient;
use App\Application\OidcFlowStore;
use App\Application\SamlClient;
use App\Entity\PlatformIntegration;
use App\Entity\User;
use App\Repository\PlatformIntegrationRepository;
use App\Repository\UserRepository;
use App\Security\OidcRoleMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SsoController
{
    public function __construct(private PlatformIntegrationRepository $integrations, private UserRepository $users, private OidcClient $oidc, private OidcFlowStore $flows, private OidcRoleMapper $roleMapper, private SamlClient $saml, private AuthController $auth, private EntityManagerInterface $entityManager, private RateLimiterFactoryInterface $loginLimiter, private string $appUrl)
    {
    }

    #[Route('/api/auth/sso/providers', methods: ['GET'])]
    public function providers(): JsonResponse
    {
        $items = array_filter($this->integrations->findBy(['enabled' => true]), static fn (PlatformIntegration $item): bool => in_array($item->getType(), ['OIDC', 'SAML'], true) && $item->isCredentialConfigured() && ([] !== ($item->getConfiguration()['accountMappings'] ?? []) || true === ($item->getConfiguration()['linkScimExternalId'] ?? false)));
        $response = new JsonResponse(['items' => array_values(array_map(static fn (PlatformIntegration $item): array => ['id' => $item->getId(), 'name' => $item->getName(), 'provider' => $item->getProvider(), 'protocol' => $item->getType()], $items))]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/api/auth/sso/{id}/start', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function start(int $id, Request $request): RedirectResponse
    {
        try {
            if (!$this->loginLimiter->create('sso-start|'.($request->getClientIp() ?? 'unknown'))->consume()->isAccepted()) {
                return $this->redirect('error');
            }
            $integration = $this->integration($id);
            $state = bin2hex(random_bytes(32));
            if ('SAML' === $integration->getType()) {
                return $this->startSaml($integration, $state);
            }
            $flow = ['integrationId' => $id, 'fingerprint' => $this->fingerprint($integration), 'nonce' => bin2hex(random_bytes(32)), 'verifier' => bin2hex(random_bytes(32)), 'expiresAt' => time() + 300];
            $url = $this->oidc->authorizationUrl($integration, $this->callbackUrl(), $state, $flow);
            $this->flows->put($state, $flow);
            $response = new RedirectResponse($url);
            $response->headers->setCookie($this->cookie('riskpilot_sso_state', $state, Cookie::SAMESITE_LAX));
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        } catch (\Throwable) {
            return $this->redirect('error');
        }
    }

    #[Route('/api/auth/sso/callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        try {
            $state = $request->query->get('state', '');
            $cookie = $request->cookies->get('riskpilot_sso_state', '');
            if ('' === $state || !hash_equals($cookie, $state)) {
                throw new \RuntimeException('État SSO invalide.');
            }
            $flow = $this->flows->take($state);
            $code = $request->query->get('code', '');
            if (null !== $flow && 'access_denied' === $request->query->get('error')) {
                return $this->redirect('cancelled');
            }
            if (null === $flow || $request->query->has('error') || '' === $code || strlen($code) > 8192) {
                throw new \RuntimeException('Parcours SSO expiré.');
            }
            $integration = $this->integration($flow['integrationId']);
            if (!hash_equals($flow['fingerprint'], $this->fingerprint($integration))) {
                throw new \RuntimeException('Configuration SSO modifiée.');
            }
            $authentication = $this->oidc->authenticate($integration, $this->callbackUrl(), $code, $flow);

            return $this->issueTicket($integration, $flow['fingerprint'], $authentication['subject'], $authentication['claims'], ['idToken' => $authentication['idToken'], 'endSessionEndpoint' => $authentication['endSessionEndpoint']]);
        } catch (\Throwable) {
            return $this->redirect('error');
        }
    }

    #[Route('/api/auth/sso/saml/{id}/acs', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function samlAssertion(int $id, Request $request): RedirectResponse
    {
        try {
            $state = $request->request->get('RelayState', '');
            $cookie = $request->cookies->get('riskpilot_sso_state', '');
            if (!is_string($state) || '' === $state || !is_string($cookie) || !hash_equals($cookie, $state)) {
                throw new \RuntimeException('État SSO invalide.');
            }
            // La demande est consommée une seule fois : une même réponse ne peut pas être rejouée.
            $flow = $this->flows->take($state);
            $response = $request->request->get('SAMLResponse', '');
            if (null === $flow || ($flow['integrationId'] ?? null) !== $id || !is_string($flow['requestId'] ?? null) || !is_string($response) || '' === $response || strlen($response) > 1_000_000) {
                throw new \RuntimeException('Parcours SSO expiré.');
            }
            $integration = $this->integration($id);
            if (!hash_equals($flow['fingerprint'], $this->fingerprint($integration))) {
                throw new \RuntimeException('Configuration SSO modifiée.');
            }
            $authentication = $this->saml->authenticate($integration, $this->appUrl, $response, $flow['requestId']);

            return $this->issueTicket($integration, $flow['fingerprint'], $authentication['subject'], $authentication['claims'], []);
        } catch (\Throwable) {
            return $this->redirect('error');
        }
    }

    #[Route('/api/auth/sso/saml/{id}/metadata', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function samlMetadata(int $id): Response
    {
        try {
            $integration = $this->integration($id);
            if ('SAML' !== $integration->getType()) {
                throw new \RuntimeException('Fournisseur non SAML.');
            }

            return new Response($this->saml->metadata($integration, $this->appUrl), 200, ['Content-Type' => 'application/samlmetadata+xml', 'Cache-Control' => 'no-store']);
        } catch (\Throwable) {
            return new Response('', 404);
        }
    }

    private function startSaml(PlatformIntegration $integration, string $state): RedirectResponse
    {
        $request = $this->saml->authorizationUrl($integration, $this->appUrl, $state);
        $this->flows->put($state, ['integrationId' => $integration->getId(), 'fingerprint' => $this->fingerprint($integration), 'requestId' => $request['requestId'], 'expiresAt' => time() + 300]);
        $response = new RedirectResponse($request['url']);
        // Le fournisseur renvoie l'utilisateur par un POST inter-sites : le cookie de liaison doit alors être SameSite=None (HTTPS).
        $response->headers->setCookie($this->cookie('riskpilot_sso_state', $state, str_starts_with($this->appUrl, 'https://') ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * Rattache l'identité vérifiée à un compte RiskPilot puis dépose un ticket à usage unique ; le MFA et les rôles
     * sont traités dans complete().
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $extra
     */
    private function issueTicket(PlatformIntegration $integration, string $fingerprint, string $subject, array $claims, array $extra): RedirectResponse
    {
        $user = $this->resolveAccount($integration, $subject);
        $this->assertAccount($user, $integration);
        $ticket = bin2hex(random_bytes(32));
        $this->flows->put($ticket, [
            'userId' => $user->getId(), 'integrationId' => $integration->getId(), 'fingerprint' => $fingerprint, 'expiresAt' => time() + 300,
            // Appliqués seulement une fois le MFA validé, dans complete().
            'mappedRoles' => $this->roleMapper->mappedRoles($claims, $integration->getConfiguration()),
        ] + $extra);
        $response = $this->redirect('complete');
        $response->headers->setCookie($this->cookie('riskpilot_sso_ticket', $ticket, Cookie::SAMESITE_STRICT));

        return $response;
    }

    /** Association explicite d'abord ; à défaut, rattachement par SCIM externalId lorsque l'administrateur l'a autorisé. */
    private function resolveAccount(PlatformIntegration $integration, string $subject): ?User
    {
        $configuration = $integration->getConfiguration();
        foreach ($configuration['accountMappings'] ?? [] as $mapping) {
            if (hash_equals($mapping['subject'], $subject)) {
                return $this->users->find($mapping['userId']);
            }
        }
        if (true === ($configuration['linkScimExternalId'] ?? false)) {
            return $this->users->findOneBy(['organization' => $integration->getOrganization(), 'scimExternalId' => $subject]);
        }

        return null;
    }

    #[Route('/api/auth/sso/complete', methods: ['POST'])]
    public function complete(Request $request): JsonResponse
    {
        $ticket = (string) $request->cookies->get('riskpilot_sso_ticket', '');
        try {
            $origin = $request->headers->get('Origin');
            $parts = parse_url($this->appUrl);
            $expectedOrigin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            if (null !== $origin && $origin !== $expectedOrigin) {
                return new JsonResponse(['code' => 'SSO_ORIGIN_REJECTED', 'message' => 'Origine de connexion non autorisée.'], 403, ['Cache-Control' => 'no-store, private']);
            }
            $limit = $this->loginLimiter->create('sso-complete|'.($request->getClientIp() ?? 'unknown'))->consume();
            if (!$limit->isAccepted()) {
                return new JsonResponse(['code' => 'TOO_MANY_SSO_ATTEMPTS', 'message' => 'Trop de tentatives. Réessayez dans une minute.'], 429, [
                    'Cache-Control' => 'no-store, private',
                    'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                ]);
            }
            $flow = $this->flows->take($ticket);
            if (null === $flow || !isset($flow['userId'])) {
                throw new \RuntimeException('Session SSO expirée.');
            }
            $integration = $this->integration($flow['integrationId']);
            $user = $this->users->find($flow['userId']);
            $this->assertAccount($user, $integration);
            if (!hash_equals($flow['fingerprint'], $this->fingerprint($integration))) {
                throw new \RuntimeException('Configuration SSO modifiée.');
            }
            if ($user->isMfaEnabled()) {
                $code = $request->toArray()['mfaCode'] ?? '';
                if (!is_string($code) || strlen($code) > 128 || '' === trim($code) || !$this->auth->validateSecondFactor($user, trim($code))) {
                    $missing = is_string($code) && '' === trim($code);
                    if (!$missing) {
                        $user->registerFailedLogin();
                        $this->entityManager->flush();
                        $flow['mfaAttempts'] = (int) ($flow['mfaAttempts'] ?? 0) + 1;
                        if ($flow['mfaAttempts'] >= 5) {
                            return $this->clearTicket(new JsonResponse(['code' => 'SSO_MFA_ATTEMPTS_EXCEEDED', 'message' => 'Trop de codes MFA incorrects. Recommencez la connexion SSO.'], 401));
                        }
                    }
                    $this->flows->put($ticket, $flow);
                    $response = new JsonResponse(['code' => $missing ? 'MFA_REQUIRED' : 'INVALID_MFA_CODE', 'mfaRequired' => true, 'message' => $missing ? 'Un code MFA est requis.' : 'Code MFA invalide.'], $missing ? 202 : 401);
                    $response->headers->set('Cache-Control', 'no-store, private');

                    return $response;
                }
            }
            if (is_array($flow['mappedRoles'] ?? null)) {
                $user->setRoles($this->roleMapper->merge($user->getRoles(), array_values(array_filter($flow['mappedRoles'], 'is_string'))));
            }
            $user->markLogin();
            $integration->markUsed();
            $this->entityManager->flush();
            $response = $this->auth->createSessionResponse($user, $request);
            $logoutUrl = $this->logoutUrl($flow, $integration);
            if (null !== $logoutUrl) {
                $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                $response->setData($payload + ['sso' => ['logoutUrl' => $logoutUrl]]);
            }
        } catch (\Throwable) {
            $response = new JsonResponse(['code' => 'SSO_SESSION_INVALID', 'message' => 'Session SSO expirée ou indisponible. Recommencez la connexion SSO.'], 401);
        }

        return $this->clearTicket($response);
    }

    /**
     * Adresse de déconnexion chez le fournisseur (OIDC RP-Initiated Logout) : l'indice id_token_hint permet
     * au fournisseur d'identifier la session à fermer sans demander de confirmation.
     *
     * @param array<string, mixed> $flow
     */
    private function logoutUrl(array $flow, PlatformIntegration $integration): ?string
    {
        $endpoint = $flow['endSessionEndpoint'] ?? null;
        $idToken = $flow['idToken'] ?? null;
        if (!is_string($endpoint) || !is_string($idToken) || '' === $idToken) {
            return null;
        }

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query([
            'id_token_hint' => $idToken,
            'client_id' => $integration->getConfiguration()['clientId'] ?? '',
            'post_logout_redirect_uri' => rtrim($this->appUrl, '/').'/login',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function clearTicket(JsonResponse $response): JsonResponse
    {
        $response->headers->clearCookie('riskpilot_sso_ticket', '/api/auth/sso', null, str_starts_with($this->appUrl, 'https://'), true, Cookie::SAMESITE_STRICT);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function integration(int $id): PlatformIntegration
    {
        $item = $this->integrations->find($id);
        if (!$item instanceof PlatformIntegration || !in_array($item->getType(), ['OIDC', 'SAML'], true) || !$item->isEnabled() || !$item->isCredentialConfigured()) {
            throw new \RuntimeException('SSO indisponible.');
        }

        return $item;
    }

    private function assertAccount(?User $user, PlatformIntegration $integration): void
    {
        if (null === $user || User::STATUS_ACTIVE !== $user->getStatus() || $user->isTemporarilyLocked() || $user->getOrganization() !== $integration->getOrganization()) {
            throw new \RuntimeException('Compte SSO non autorisé.');
        }
    }

    private function fingerprint(PlatformIntegration $item): string
    {
        return hash('sha256', json_encode($item->getConfiguration(), JSON_THROW_ON_ERROR).$item->getEncryptedCredential());
    }

    private function callbackUrl(): string
    {
        return rtrim($this->appUrl, '/').'/api/auth/sso/callback';
    }

    private function cookie(string $name, string $value, string $sameSite): Cookie
    {
        return Cookie::create($name, $value)->withExpires(new \DateTimeImmutable('+5 minutes'))->withPath('/api/auth/sso')->withSecure(str_starts_with($this->appUrl, 'https://'))->withHttpOnly(true)->withSameSite($sameSite);
    }

    private function redirect(string $status): RedirectResponse
    {
        $response = new RedirectResponse(rtrim($this->appUrl, '/').'/login?sso='.$status);
        $response->headers->clearCookie('riskpilot_sso_state', '/api/auth/sso', null, str_starts_with($this->appUrl, 'https://'), true, Cookie::SAMESITE_LAX);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
