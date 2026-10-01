<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Application\CurrentUser;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/notifications')]
final readonly class NotificationController
{
    public function __construct(private NotificationRepository $notifications, private CurrentUser $currentUser, private ApiResponseFactory $responses, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $limit = filter_var($request->query->get('limit', '50'), FILTER_VALIDATE_INT);
        $offset = filter_var($request->query->get('offset', '0'), FILTER_VALIDATE_INT);
        $unreadOnly = filter_var($request->query->get('unreadOnly', 'false'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (false === $limit || 1 > $limit || 100 < $limit || false === $offset || 0 > $offset || 100000 < $offset || null === $unreadOnly) {
            return $this->response(['code' => 'INVALID_FILTER', 'message' => 'Pagination ou filtre de notifications invalide.'], 422);
        }
        $user = $this->currentUser->get();
        $response = $this->response(array_map($this->responses->notification(...), $this->notifications->findFor($user, $limit, $offset, $unreadOnly)));
        $response->headers->set('X-Total-Count', (string) $this->notifications->countFor($user, $unreadOnly));

        return $response;
    }

    #[Route('/summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $user = $this->currentUser->get();

        return $this->response(['total' => $this->notifications->countFor($user), 'unread' => $this->notifications->countFor($user, true)]);
    }

    #[Route('/read-all', methods: ['PUT'])]
    public function readAll(): JsonResponse
    {
        return $this->response(['updated' => $this->notifications->markAllReadFor($this->currentUser->get())]);
    }

    #[Route('/{id<\d+>}/read', methods: ['PUT'])]
    public function read(int $id): JsonResponse
    {
        $item = $this->notifications->findOneFor($id, $this->currentUser->get());
        if (null === $item) {
            return $this->response(['code' => 'NOT_FOUND', 'message' => 'Notification introuvable.'], 404);
        }
        if (!$item->isRead()) {
            $item->markRead();
            $this->entityManager->flush();
        }

        return $this->response($this->responses->notification($item));
    }

    /** @param array<mixed> $payload */
    private function response(array $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse($payload, $status, ['Cache-Control' => 'private, no-store']);
    }
}
