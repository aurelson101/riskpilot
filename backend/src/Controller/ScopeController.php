<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Api\Dto\ScopeInput;
use App\Api\JsonInputMapper;
use App\Application\CurrentUser;
use App\Entity\Scope;
use App\Entity\User;
use App\Repository\ScopeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/scopes')]
final readonly class ScopeController
{
    public function __construct(private ScopeRepository $scopes, private UserRepository $users, private CurrentUser $currentUser, private EntityManagerInterface $entityManager, private JsonInputMapper $mapper, private ApiResponseFactory $responses)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->responses->scope(...), $this->scopes->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $scope = $this->scopes->findOneVisibleTo($id, $this->currentUser->get());

        return null === $scope ? $this->notFound() : new JsonResponse($this->responses->scope($scope));
    }

    #[Route('', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        return $this->save(new Scope('', 'DEPARTMENT', $this->currentUser->get()->getOrganization()), $request, true);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function update(int $id, Request $request): JsonResponse
    {
        $scope = $this->scopes->findOneVisibleTo($id, $this->currentUser->get());

        return null === $scope ? $this->notFound() : $this->save($scope, $request, false);
    }

    private function save(Scope $scope, Request $request, bool $created): JsonResponse
    {
        [$input, $violations] = $this->mapper->map($request, ScopeInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }
        $actor = $this->currentUser->get();
        $parent = null === $input->parentScopeId ? null : $this->scopes->findOneVisibleTo($input->parentScopeId, $actor);
        $owner = null === $input->ownerId ? null : $this->users->findOneVisibleTo($input->ownerId, $actor);
        if ((null !== $input->parentScopeId && null === $parent) || (null !== $input->ownerId && null === $owner) || $parent === $scope) {
            return new JsonResponse(['code' => 'INVALID_RELATION', 'message' => 'Le parent ou le responsable est invalide.'], 422);
        }
        $scope->setName($input->name)->setDescription($input->description)->setType($input->type)->setParentScope($parent)->setOwner($owner)->setStatus($input->status);
        $this->entityManager->persist($scope);
        $this->entityManager->flush();

        return new JsonResponse($this->responses->scope($scope), $created ? 201 : 200);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Périmètre introuvable.'], 404);
    }
}
