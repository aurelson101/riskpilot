<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\AuthSession;
use App\Repository\AuthSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/me/sessions')]
final readonly class SessionController
{
    public function __construct(
        private CurrentUser $currentUser,
        private AuthSessionRepository $sessions,
        private EntityManagerInterface $entityManager,
        private string $appUrl,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $current = $this->currentSession($request);

        return new JsonResponse(array_map(
            static fn (AuthSession $session): array => [
                'id' => $session->getId(),
                'userAgent' => $session->getUserAgent(),
                'ipAddress' => $session->getIpAddress(),
                'createdAt' => $session->getCreatedAt()->format(DATE_ATOM),
                'lastUsedAt' => $session->getLastUsedAt()->format(DATE_ATOM),
                'expiresAt' => $session->getExpiresAt()->format(DATE_ATOM),
                'active' => $session->isActive(),
                'current' => $session === $current,
            ],
            $this->sessions->findForUser($this->currentUser->get()),
        ));
    }

    #[Route('/{id<\d+>}', methods: ['DELETE'])]
    public function revoke(int $id): Response
    {
        $session = $this->sessions->find($id);
        if (!$session instanceof AuthSession || $session->getUser() !== $this->currentUser->get()) {
            return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Session introuvable.'], Response::HTTP_NOT_FOUND);
        }
        $session->revoke();
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('', methods: ['DELETE'])]
    public function revokeAll(): Response
    {
        $this->sessions->revokeAll($this->currentUser->get());
        $this->entityManager->flush();
        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->clearCookie('riskpilot_refresh', '/api', null, str_starts_with($this->appUrl, 'https://'), true, Cookie::SAMESITE_STRICT);

        return $response;
    }

    private function currentSession(Request $request): ?AuthSession
    {
        $token = (string) $request->cookies->get('riskpilot_refresh', '');

        return '' === $token ? null : $this->sessions->findByRefreshToken($token);
    }
}
