<?php

declare(strict_types=1);

namespace App\Application;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OidcDiscoveryValidator
{
    private const SAFE_SIGNING_ALGORITHMS = ['RS256', 'PS256', 'ES256'];

    public function __construct(private HttpClientInterface $httpClient, private array $allowedIssuers = [])
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
                'max_duration' => 10,
                'verify_peer' => true,
                'verify_host' => true,
                'on_progress' => static function (int $downloaded, int $total): void {
                    if ($downloaded > 262144 || $total > 262144) throw new \RuntimeException('Configuration OIDC trop volumineuse.');
                },
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Découverte OIDC inaccessible.', previous: $e);
        }
        try {
            if (200 !== $response->getStatusCode()) throw new \RuntimeException('Statut OIDC invalide.');
            $content = $response->getContent(false);
            if (strlen($content) > 262144) throw new \RuntimeException('Configuration OIDC trop volumineuse.');
            $metadata = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($metadata) || array_is_list($metadata)) throw new \RuntimeException('Objet OIDC requis.');
        } catch (\Throwable $e) {
            throw new \RuntimeException('La configuration OIDC reçue est invalide.', previous: $e);
        }

        if (!is_string($metadata['issuer'] ?? null) || rtrim($metadata['issuer'], '/') !== $issuer) {
            throw new \RuntimeException('L’émetteur annoncé par le fournisseur OIDC ne correspond pas à la configuration.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
            if (!is_string($metadata[$field] ?? null) || !$this->validHttpsUrl($metadata[$field])) {
                throw new \RuntimeException('La configuration OIDC contient un endpoint non sécurisé.');
            }
        }
        foreach (['response_types_supported', 'scopes_supported', 'id_token_signing_alg_values_supported', 'code_challenge_methods_supported'] as $field) {
            $values = $metadata[$field] ?? [];
            if (!is_array($values) || !array_is_list($values) || [] !== array_filter($values, static fn ($value): bool => !is_string($value))) {
                throw new \RuntimeException('Les capacités OIDC doivent être des listes de chaînes.');
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

        if (in_array($provider, ['KEYCLOAK', 'AUTHENTIK'], true) && $this->validHttpsUrl($issuer) && !isset(parse_url($issuer)['query'])) {
            $allowed = array_map(static fn ($value): string => is_string($value) ? rtrim(trim($value), '/') : '', $this->allowedIssuers);
            $path = parse_url($issuer, PHP_URL_PATH);
            $expected = 'KEYCLOAK' === $provider ? '#/realms/[^/]+$#' : '#/application/o/[^/]+$#';
            if (is_string($path) && preg_match($expected, $path) && in_array($issuer, $allowed, true)) return;
            throw new \RuntimeException('Cet émetteur doit être autorisé par l’administrateur serveur dans OIDC_DIAGNOSTIC_ISSUERS, avec le chemin realm Keycloak ou application Authentik.');
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
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }
}
