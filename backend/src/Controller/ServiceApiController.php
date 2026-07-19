<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PlatformIntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/service')] final readonly class ServiceApiController
{
    public function __construct(private PlatformIntegrationRepository $repository, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $plain = trim((string) $request->headers->get('X-RiskPilot-Key', ''));
        $item = '' === $plain ? null : $this->repository->findApiKey(substr($plain, 0, 12));
        if (null === $item || !$item->verifies($plain)) {
            return new JsonResponse(['code' => 'INVALID_SERVICE_KEY', 'message' => 'Clé de service invalide.'], 401);
        }
        $item->markUsed();
        $this->entityManager->flush();

        return new JsonResponse(['apiVersion' => '1', 'status' => 'ok', 'organizationId' => $item->getOrganization()->getId(), 'scopes' => $item->getConfiguration()['scopes'] ?? []]);
    }
}
