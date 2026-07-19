<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/audit-logs')] #[IsGranted(User::ROLE_ADMIN)]
final readonly class AuditLogController
{
    public function __construct(private AuditLogRepository $logs, private CurrentUser $currentUser, private string $appSecret)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->serialize(...), $this->logs->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/integrity', methods: ['GET'])]
    public function integrity(): JsonResponse
    {
        $logs = $this->logs->findSealedFor($this->currentUser->get());
        $previousHash = null;
        $brokenAt = null;
        foreach ($logs as $log) {
            if (!$log->verify($previousHash)) {
                $brokenAt = $log->getId();
                break;
            }
            $previousHash = $log->getEventHash();
        }

        return new JsonResponse([
            'valid' => null === $brokenAt,
            'sealedEvents' => count($logs),
            'brokenAt' => $brokenAt,
            'latestHash' => $previousHash,
            'verifiedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ], null === $brokenAt ? Response::HTTP_OK : Response::HTTP_CONFLICT);
    }

    #[Route('/export', methods: ['GET'])]
    public function export(): Response
    {
        $data = array_map($this->serialize(...), $this->logs->findVisibleTo($this->currentUser->get()));
        $payload = json_encode(['exportedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'events' => $data], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $payload, $this->appSecret);

        return new Response($payload, Response::HTTP_OK, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="riskpilot-audit.json"',
            'X-RiskPilot-Signature' => $signature,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(AuditLog $log): array
    {
        return ['id' => $log->getId(), 'user' => null === $log->getUser() ? null : ['id' => $log->getUser()->getId(), 'name' => $log->getUser()->getFirstName().' '.$log->getUser()->getLastName(), 'email' => $log->getUser()->getEmail()], 'action' => $log->getAction(), 'entityType' => $log->getEntityType(), 'entityId' => $log->getEntityId(), 'oldValues' => $log->getOldValues(), 'newValues' => $log->getNewValues(), 'ipAddress' => $log->getIpAddress(), 'userAgent' => $log->getUserAgent(), 'requestId' => $log->getRequestId(), 'previousHash' => $log->getPreviousHash(), 'eventHash' => $log->getEventHash(), 'createdAt' => $log->getCreatedAt()->format(DATE_ATOM)];
    }
}
