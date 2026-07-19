<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Application\CurrentUser;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/notifications')]
final readonly class NotificationController
{
    public function __construct(private NotificationRepository $notifications, private CurrentUser $currentUser, private ApiResponseFactory $responses, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->responses->notification(...), $this->notifications->findFor($this->currentUser->get())));
    }

    #[Route('/{id<\d+>}/read', methods: ['PUT'])]
    public function read(int $id): JsonResponse
    {
        $item = $this->notifications->findOneFor($id, $this->currentUser->get());
        if (null === $item) {
            return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Notification introuvable.'], 404);
        } $item->markRead();
        $this->entityManager->flush();

        return new JsonResponse($this->responses->notification($item));
    }
}
