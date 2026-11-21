<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Repository\PlatformIntegrationRepository;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Journalise dans la chaîne d'audit scellée les écritures reçues par SCIM. Elles sont effectuées avec une clé de service,
 * sans utilisateur connecté : l'AuditSubscriber habituel ne les voit donc pas.
 */
#[AsEventListener(event: 'kernel.response')]
final readonly class ScimAuditSubscriber
{
    public function __construct(private EntityManagerInterface $entityManager, private AuditLogRepository $logs, private PlatformIntegrationRepository $integrations)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $integrationId = $request->attributes->get('_scim_integration');
        if (!is_int($integrationId) || $request->isMethodSafe() || $event->getResponse()->getStatusCode() >= 400 || !str_starts_with($request->getPathInfo(), '/api/scim/v2/')) {
            return;
        }
        $integration = $this->integrations->find($integrationId);
        if (null === $integration) {
            return;
        }
        $organization = $integration->getOrganization();
        $segments = array_values(array_filter(explode('/', $request->getPathInfo())));
        $resource = $segments[3] ?? 'scim';
        $identifier = $segments[4] ?? null;
        $payload = [];
        if ('' !== $request->getContent()) {
            try {
                $payload = $this->minimal($request->toArray());
            } catch (\Throwable) {
                $payload = [];
            }
        }
        $log = new AuditLog($organization, null, $request->getMethod(), 'scim-'.strtolower($resource), null !== $identifier && ctype_alnum($identifier) ? $identifier : null, ['serviceKey' => $integration->getName(), 'request' => $payload], $request->getClientIp(), $request->headers->get('User-Agent'));
        $requestId = mb_substr((string) ($request->headers->get('X-Request-ID') ?: bin2hex(random_bytes(16))), 0, 36);
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $connection->executeQuery('SELECT pg_advisory_xact_lock(:organization)', ['organization' => (int) $organization->getId()]);
            }
            $log->seal($this->logs->latestHashForOrganization($organization), $requestId);
            $this->entityManager->persist($log);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }

    /**
     * Conserve les seuls attributs de gestion de compte (pas de données personnelles superflues).
     *
     * @param array<array-key, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function minimal(array $body): array
    {
        $kept = [];
        foreach (['userName', 'externalId', 'active', 'displayName'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                $kept[$key] = $body[$key];
            }
        }
        if (is_array($body['Operations'] ?? null)) {
            $kept['operations'] = count($body['Operations']);
        }
        if (is_array($body['members'] ?? null)) {
            $kept['members'] = count($body['members']);
        }

        return $kept;
    }
}
