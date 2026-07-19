<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Api\Dto\SecurityControlInput;
use App\Api\JsonInputMapper;
use App\Application\CurrentUser;
use App\Entity\SecurityControl;
use App\Entity\User;
use App\Repository\SecurityControlRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/security-controls')]
final readonly class SecurityControlController
{
    public function __construct(private SecurityControlRepository $controls, private UserRepository $users, private CurrentUser $currentUser, private EntityManagerInterface $entityManager, private JsonInputMapper $mapper, private ApiResponseFactory $responses)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->responses->securityControl(...), $this->controls->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $item = $this->controls->findOneVisibleTo($id, $this->currentUser->get());

        return null === $item ? $this->notFound() : new JsonResponse($this->responses->securityControl($item));
    }

    #[Route('', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        return $this->save(null, $request);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function update(int $id, Request $request): JsonResponse
    {
        $item = $this->controls->findOneVisibleTo($id, $this->currentUser->get());

        return null === $item ? $this->notFound() : $this->save($item, $request);
    }

    private function save(?SecurityControl $item, Request $request): JsonResponse
    {
        [$input, $violations] = $this->mapper->map($request, SecurityControlInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }
        $actor = $this->currentUser->get();
        $owner = null === $input->ownerId ? null : $this->users->findOneVisibleTo($input->ownerId, $actor);
        if (null !== $input->ownerId && null === $owner) {
            return new JsonResponse(['code' => 'INVALID_OWNER', 'message' => 'Responsable invalide.'], 422);
        }
        $created = null === $item;
        $item ??= new SecurityControl($input->name, $input->category, $actor->getOrganization());
        $item->setName($input->name)->setDescription($input->description)->setCategory($input->category)->setEffectiveness($input->effectiveness)->setImplementationStatus($input->implementationStatus)->setOwner($owner);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return new JsonResponse($this->responses->securityControl($item), $created ? 201 : 200);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Mesure de sécurité introuvable.'], 404);
    }
}
