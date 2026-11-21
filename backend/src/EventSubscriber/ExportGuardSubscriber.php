<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Application\AuditTrail;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Encadre les exports de données : débit limité par utilisateur (avant génération) et chaque téléchargement réussi
 * est inscrit dans la chaîne d'audit scellée (qui, quoi, quand, quel volume). Les lectures ordinaires restent hors journal.
 */
final readonly class ExportGuardSubscriber
{
    private const EXPORT_PATH = '~^/api/(exports/|.+/export(\.(pdf|csv|xlsx))?$|.+\.(csv|xlsx|pdf)$)~';

    public function __construct(private Security $security, private RateLimiterFactoryInterface $dataExportLimiter, private AuditTrail $trail)
    {
    }

    #[AsEventListener(event: 'kernel.request', priority: -10)]
    public function limit(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $user = $this->security->getUser();
        if (!$event->isMainRequest() || !$request->isMethod('GET') || !$user instanceof User || 1 !== preg_match(self::EXPORT_PATH, $request->getPathInfo())) {
            return;
        }
        $limit = $this->dataExportLimiter->create('export-user-'.$user->getId())->consume();
        if (!$limit->isAccepted()) {
            $event->setResponse(new JsonResponse(['code' => 'EXPORT_RATE_LIMIT', 'message' => 'Trop d’exports en peu de temps. Réessayez dans une minute.'], 429, [
                'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                'Cache-Control' => 'no-store, private',
            ]));
        }
    }

    #[AsEventListener(event: 'kernel.response')]
    public function audit(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $user = $this->security->getUser();
        $disposition = (string) $response->headers->get('Content-Disposition', '');
        if (!$event->isMainRequest() || !$request->isMethod('GET') || !$user instanceof User || 200 !== $response->getStatusCode()
            || !str_starts_with($disposition, 'attachment') || !str_starts_with($request->getPathInfo(), '/api/') || str_starts_with($request->getPathInfo(), '/api/public/')) {
            return;
        }
        $segments = array_values(array_filter(explode('/', $request->getPathInfo())));
        preg_match('/filename="([^"]{1,200})"/', $disposition, $name);
        $this->trail->record(
            $user->getOrganization(), $user, 'EXPORT', mb_substr($segments[1] ?? 'api', 0, 100), null,
            ['path' => mb_substr($request->getPathInfo(), 0, 200), 'file' => $name[1] ?? null, 'bytes' => strlen((string) $response->getContent())],
            $request->getClientIp(), $request->headers->get('User-Agent'), (string) ($request->headers->get('X-Request-ID') ?: bin2hex(random_bytes(16))),
        );
    }
}
