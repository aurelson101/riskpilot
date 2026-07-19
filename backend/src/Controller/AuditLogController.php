<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/audit-logs')] #[IsGranted(User::ROLE_ADMIN)]
final readonly class AuditLogController
{
    public function __construct(private AuditLogRepository $logs, private CurrentUser $currentUser)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map(static fn (AuditLog $log): array => ['id' => $log->getId(), 'user' => null === $log->getUser() ? null : ['id' => $log->getUser()->getId(), 'name' => $log->getUser()->getFirstName().' '.$log->getUser()->getLastName(), 'email' => $log->getUser()->getEmail()], 'action' => $log->getAction(), 'entityType' => $log->getEntityType(), 'entityId' => $log->getEntityId(), 'newValues' => $log->getNewValues(), 'ipAddress' => $log->getIpAddress(), 'userAgent' => $log->getUserAgent(), 'createdAt' => $log->getCreatedAt()->format(DATE_ATOM)], $this->logs->findVisibleTo($this->currentUser->get())));
    }
}
