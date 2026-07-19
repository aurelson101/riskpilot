<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\IsmsDocumentShareRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PublicIsmsDocumentController
{
    public function __construct(private IsmsDocumentShareRepository $shares, private EntityManagerInterface $entityManager, private RateLimiterFactory $shareLimiter)
    {
    }

    #[Route('/api/public/documents/{token}', methods: ['GET', 'POST'])]
    public function show(string $token, Request $request): JsonResponse
    {
        $limit = $this->shareLimiter->create(($request->getClientIp() ?? 'unknown').'|'.hash('sha256', $token))->consume();
        if (!$limit->isAccepted()) {
            return new JsonResponse(['code' => 'TOO_MANY_ATTEMPTS', 'message' => 'Trop de tentatives. Réessayez dans une minute.'], 429);
        }
        $share = $this->shares->findByToken($token);
        if (null === $share || !$share->isAvailable()) {
            return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Partage introuvable ou expiré.'], 404);
        }
        $input = json_decode($request->getContent(), true);
        $password = is_array($input) ? ($input['password'] ?? null) : null;
        if ($share->hasPassword() && 'GET' === $request->getMethod()) {
            return new JsonResponse(['passwordRequired' => true]);
        }
        if (!$share->verifiesPassword(is_string($password) ? $password : null)) {
            return new JsonResponse(['code' => 'INVALID_PASSWORD', 'message' => 'Mot de passe incorrect.'], 403);
        }
        $share->recordAccess();
        $this->entityManager->flush();
        $document = $share->getDocument();

        return new JsonResponse(['passwordRequired' => false, 'document' => ['title' => $document->getTitle(), 'category' => $document->getCategory(), 'classification' => $document->getClassification(), 'status' => $document->getStatus(), 'content' => $document->getContent(), 'version' => $document->getCurrentVersion(), 'updatedAt' => $document->getUpdatedAt()->format(DATE_ATOM)]]);
    }
}
