<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Api\Dto\ThreatInput;
use App\Api\JsonInputMapper;
use App\Application\CurrentUser;
use App\Entity\Threat;
use App\Entity\User;
use App\Repository\ThreatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/threats')]
final readonly class ThreatController
{
    public function __construct(private ThreatRepository $threats, private CurrentUser $currentUser, private EntityManagerInterface $entityManager, private JsonInputMapper $mapper, private ApiResponseFactory $responses)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->responses->threat(...), $this->threats->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $item = $this->threats->findOneVisibleTo($id, $this->currentUser->get());

        return null === $item ? $this->notFound() : new JsonResponse($this->responses->threat($item));
    }

    #[Route('', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        return $this->save(new Threat('', '', $this->currentUser->get()->getOrganization()), $request, true);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function update(int $id, Request $request): JsonResponse
    {
        $item = $this->threats->findOneVisibleTo($id, $this->currentUser->get());

        return null === $item ? $this->notFound() : $this->save($item, $request, false);
    }

    private function save(Threat $item, Request $request, bool $created): JsonResponse
    {
        [$input, $violations] = $this->mapper->map($request, ThreatInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        } $item->setName($input->name)->setDescription($input->description)->setCategory($input->category)->setSource($input->source)->setStatus($input->status);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return new JsonResponse($this->responses->threat($item), $created ? 201 : 200);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Menace introuvable.'], 404);
    }
}
