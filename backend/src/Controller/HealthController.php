<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    public function __construct(private Connection $connection, private string $redisUrl)
    {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $database = $this->databaseIsReady();
        $redis = $this->redisIsReady();

        return new JsonResponse([
            'status' => $database && $redis ? 'ok' : 'degraded',
            'service' => 'riskpilot-api',
            'checks' => ['database' => $database ? 'ok' : 'error', 'redis' => $redis ? 'ok' : 'error'],
            'checkedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ], $database && $redis ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function databaseIsReady(): bool
    {
        try {
            return 1 === (int) $this->connection->fetchOne('SELECT 1');
        } catch (\Throwable) {
            return false;
        }
    }

    private function redisIsReady(): bool
    {
        $parts = parse_url($this->redisUrl);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false;
        }
        try {
            $redis = new \Redis();
            $redis->connect((string) $parts['host'], (int) ($parts['port'] ?? 6379), 1.0);
            if (isset($parts['pass'])) {
                $redis->auth((string) $parts['pass']);
            }
            $pong = $redis->ping();
            $ready = '+PONG' === $pong || true === $pong;
            $redis->close();

            return $ready;
        } catch (\Throwable) {
            return false;
        }
    }
}
