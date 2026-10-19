<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\PlatformIntegration;
use App\Security\OidcIdTokenVerifier;
use App\Security\SecretCipher;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OidcClient
{
    public function __construct(private HttpClientInterface $httpClient, private OidcDiscoveryValidator $discovery, private OidcIdTokenVerifier $verifier, private SecretCipher $cipher)
    {
    }

    /** @param array<string, mixed> $flow */
    public function authorizationUrl(PlatformIntegration $integration, string $redirectUri, string $state, array $flow): string
    {
        $configuration = $integration->getConfiguration();
        $metadata = $this->discovery->discover($integration->getProvider(), $configuration['issuer']);
        if (!in_array('RS256', $metadata['id_token_signing_alg_values_supported'], true)) {
            throw new \RuntimeException('Le SSO nécessite RS256.');
        }

        return $metadata['authorization_endpoint'].'?'.http_build_query([
            'client_id' => $configuration['clientId'], 'redirect_uri' => $redirectUri,
            'response_type' => 'code', 'response_mode' => 'query', 'scope' => 'openid profile email',
            'state' => $state, 'nonce' => $flow['nonce'],
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            ...('GOOGLE_WORKSPACE' === $integration->getProvider() && !empty($configuration['hostedDomain']) ? ['hd' => $configuration['hostedDomain']] : []),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, mixed> $flow */
    public function identity(PlatformIntegration $integration, string $redirectUri, string $code, array $flow): string
    {
        $configuration = $integration->getConfiguration();
        $metadata = $this->discovery->discover($integration->getProvider(), $configuration['issuer']);
        $authMethods = $metadata['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        $body = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $flow['verifier']];
        $secret = $this->cipher->decrypt((string) $integration->getEncryptedCredential());
        if (in_array('client_secret_basic', $authMethods, true)) {
            $options = ['body' => $body, 'auth_basic' => [urlencode($configuration['clientId']), urlencode($secret)]];
        } elseif (in_array('client_secret_post', $authMethods, true)) {
            $body += ['client_id' => $configuration['clientId'], 'client_secret' => $secret];
            $options = ['body' => $body];
        } else {
            throw new \RuntimeException('Authentification du client OIDC non prise en charge.');
        }
        $tokens = $this->json('POST', $metadata['token_endpoint'], $options);
        if (!is_string($tokens['id_token'] ?? null)) {
            throw new \RuntimeException('Jeton d’identité OIDC manquant.');
        }
        $jwks = $this->json('GET', $metadata['jwks_uri']);
        $claims = $this->verifier->verify($tokens['id_token'], $jwks, $metadata['issuer'], $configuration['clientId'], $flow['nonce']);
        if ('GOOGLE_WORKSPACE' === $integration->getProvider() && !empty($configuration['hostedDomain']) && ($claims['hd'] ?? null) !== $configuration['hostedDomain']) {
            throw new \RuntimeException('Domaine Google Workspace non autorisé.');
        }
        if ('MICROSOFT_ENTRA' === $integration->getProvider()) {
            $tenant = explode('/', trim(parse_url($metadata['issuer'], PHP_URL_PATH), '/'))[0];
            if (($claims['tid'] ?? null) !== $tenant || !is_string($claims['oid'] ?? null) || !preg_match('/^[a-f0-9-]{36}$/Di', $claims['oid'])) {
                throw new \RuntimeException('Identité Microsoft Entra invalide.');
            }

            return $claims['oid'];
        }

        return $claims['sub'];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, array $options = []): array
    {
        $response = $this->httpClient->request($method, $url, $options + [
            'headers' => ['Accept' => 'application/json'], 'max_redirects' => 0,
            'timeout' => 5, 'max_duration' => 10, 'verify_peer' => true, 'verify_host' => true,
            'on_progress' => static function (int $downloaded, int $total): void {
                if ($downloaded > 262144 || $total > 262144) {
                    throw new \RuntimeException('Réponse OIDC trop volumineuse.');
                }
            },
        ]);
        $content = $response->getContent(false);
        if (200 !== $response->getStatusCode() || strlen($content) > 262144) {
            throw new \RuntimeException('Réponse OIDC invalide.');
        }
        $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new \RuntimeException('Objet OIDC requis.');
        }

        return $data;
    }
}
