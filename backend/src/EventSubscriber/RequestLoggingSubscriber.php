<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final readonly class RequestLoggingSubscriber
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    #[AsEventListener(event: 'kernel.request', priority: 100)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set('_request_started_at', hrtime(true));
        }
    }

    #[AsEventListener(event: 'kernel.response', priority: -100)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        $requestId = (string) ($response->headers->get('X-Request-ID') ?: $request->headers->get('X-Request-ID') ?: bin2hex(random_bytes(16)));
        $response->headers->set('X-Request-ID', mb_substr($requestId, 0, 36));
        $startedAt = $request->attributes->get('_request_started_at');
        $durationMs = is_int($startedAt) ? round((hrtime(true) - $startedAt) / 1_000_000, 2) : null;
        $this->logger->info('http_request', ['request_id' => $requestId, 'method' => $request->getMethod(), 'path' => $request->getPathInfo(), 'status' => $response->getStatusCode(), 'duration_ms' => $durationMs, 'client_ip' => $request->getClientIp()]);
    }
}
