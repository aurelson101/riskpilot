<?php

declare(strict_types=1);

namespace App\Application;

use Symfony\Component\Cache\Adapter\RedisAdapter;

final readonly class OidcFlowStore
{
    public function __construct(private string $redisUrl)
    {
    }

    /** @param array<string, mixed> $data */
    public function put(string $ticket, array $data): void
    {
        $this->redis()->setex($this->key($ticket), 300, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** Atomically consume a flow or handoff ticket. @return array<string, mixed>|null */
    public function take(string $ticket): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $ticket)) {
            return null;
        }
        $raw = $this->redis()->getDel($this->key($ticket));
        $data = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;

        return is_array($data) && ($data['expiresAt'] ?? 0) > time() ? $data : null;
    }

    private function key(string $ticket): string
    {
        return 'riskpilot:sso:'.hash('sha256', $ticket);
    }

    private function redis(): \Redis
    {
        $redis = RedisAdapter::createConnection($this->redisUrl, ['timeout' => 3, 'read_timeout' => 3]);
        if (!$redis instanceof \Redis) {
            throw new \RuntimeException('Stockage SSO indisponible.');
        }

        return $redis;
    }
}
