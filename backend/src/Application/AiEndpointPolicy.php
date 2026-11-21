<?php

declare(strict_types=1);

namespace App\Application;

/**
 * Points d'accès IA auto-hébergés (Ollama, vLLM, LocalAI, LiteLLM… compatibles API « chat completions ») autorisés par
 * l'exploitant du serveur via AI_SELF_HOSTED_ENDPOINTS. Un administrateur d'organisation ne peut choisir qu'une adresse de
 * cette liste : il ne peut donc pas diriger le serveur vers un hôte arbitraire (SSRF), y compris sur le réseau interne,
 * qui est précisément l'usage visé pour un modèle hébergé en interne.
 */
final readonly class AiEndpointPolicy
{
    /** @param list<mixed> $endpoints */
    public function __construct(private array $endpoints = [])
    {
    }

    /** @return list<string> */
    public function endpoints(): array
    {
        $valid = [];
        foreach ($this->endpoints as $endpoint) {
            $normalized = is_string($endpoint) ? $this->normalize($endpoint) : null;
            if (null !== $normalized) {
                $valid[$normalized] = true;
            }
        }

        return array_keys($valid);
    }

    public function allows(string $url): bool
    {
        $normalized = $this->normalize($url);

        return null !== $normalized && in_array($normalized, $this->endpoints(), true);
    }

    private function normalize(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if ('' === $url || strlen($url) > 255 || false === filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || 1 === preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }

        return $url;
    }
}
