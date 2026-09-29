<?php

declare(strict_types=1);

namespace App\Application;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OidcDiscoveryValidator
{
    private const SAFE_SIGNING_ALGORITHMS = ['RS256', 'PS256', 'ES256'];

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /** @return array<string, mixed> */
    public function validate(string $provider, string $issuer): array
    {
        $provider = strtoupper(trim($provider));
        $issuer = rtrim(trim($issuer), '/');
        $this->assertKnownIssuer($provider, $issuer);

        try {
            $response = $this->httpClient->request('GET', $issuer.'/.well-known/openid-configuration', [
                'headers' => ['Accept' => 'application/json'],
                'max_redirects' => 0,
                'timeout' => 5,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Découverte OIDC inaccessible.', previous: $e);
        }
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('Le fournisseur OIDC n’a pas renvoyé une configuration valide.');
        }
        try {
            $metadata = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('La configuration OIDC reçue est invalide.', previous: $e);
        }

        if (rtrim((string) ($metadata['issuer'] ?? ''), '/') !== $issuer) {
            throw new \RuntimeException('L’émetteur annoncé par le fournisseur OIDC ne correspond pas à la configuration.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
            if (!$this->validHttpsUrl((string) ($metadata[$field] ?? ''))) {
                throw new \RuntimeException('La configuration OIDC contient un endpoint non sécurisé.');
            }
        }
        if (!in_array('code', (array) ($metadata['response_types_supported'] ?? []), true)
            || !in_array('openid', (array) ($metadata['scopes_supported'] ?? []), true)) {
            throw new \RuntimeException('Le fournisseur ne prend pas en charge le flux OIDC Authorization Code requis.');
        }
        $algorithms = array_values(array_intersect(self::SAFE_SIGNING_ALGORITHMS, (array) ($metadata['id_token_signing_alg_values_supported'] ?? [])));
        if ([] === $algorithms) {
            throw new \RuntimeException('Aucun algorithme sûr de signature des jetons OIDC n’est annoncé.');
        }

        return [
            'validated' => true,
            'provider' => $provider,
            'issuer' => $issuer,
            'authorizationCode' => true,
            'pkceS256' => in_array('S256', (array) ($metadata['code_challenge_methods_supported'] ?? []), true),
            'signingAlgorithms' => $algorithms,
        ];
    }

    private function assertKnownIssuer(string $provider, string $issuer): void
    {
        if ('GOOGLE_WORKSPACE' === $provider && 'https://accounts.google.com' === $issuer) {
            return;
        }
        if ('MICROSOFT_ENTRA' === $provider && preg_match('#^https://login\.microsoftonline\.com/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/v2\.0$#i', $issuer)) {
            return;
        }

        throw new \RuntimeException('Utilisez l’émetteur officiel Google ou l’URL Entra ID avec l’identifiant UUID du tenant.');
    }

    private function validHttpsUrl(string $url): bool
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && 'https' === strtolower((string) ($parts['scheme'] ?? ''))
            && '' !== (string) ($parts['host'] ?? '')
            && !isset($parts['user'], $parts['pass']);
    }
}
