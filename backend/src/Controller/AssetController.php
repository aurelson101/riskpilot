<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Api\Dto\AssetInput;
use App\Api\JsonInputMapper;
use App\Application\CurrentUser;
use App\Entity\Asset;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\ScopeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/assets')]
final readonly class AssetController
{
    public function __construct(private AssetRepository $assets, private ScopeRepository $scopes, private UserRepository $users, private CurrentUser $currentUser, private EntityManagerInterface $entityManager, private JsonInputMapper $mapper, private ApiResponseFactory $responses)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->responses->asset(...), $this->assets->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $asset = $this->assets->findOneVisibleTo($id, $this->currentUser->get());

        return null === $asset ? $this->notFound() : new JsonResponse($this->responses->asset($asset));
    }

    #[Route('', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        return $this->save(null, $request);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function update(int $id, Request $request): JsonResponse
    {
        $asset = $this->assets->findOneVisibleTo($id, $this->currentUser->get());

        return null === $asset ? $this->notFound() : $this->save($asset, $request);
    }

    #[Route('/{id<\d+>}', methods: ['DELETE'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function delete(int $id): JsonResponse
    {
        $asset = $this->assets->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $asset) {
            return $this->notFound();
        }
        $this->entityManager->remove($asset);
        try {
            $this->entityManager->flush();
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            return new JsonResponse(['code' => 'RESOURCE_IN_USE', 'message' => 'Cet actif est utilisé par un risque et ne peut pas être supprimé.'], 409);
        }

        return new JsonResponse(null, 204);
    }

    private function save(?Asset $asset, Request $request): JsonResponse
    {
        [$input, $violations] = $this->mapper->map($request, AssetInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }
        $actor = $this->currentUser->get();
        $scope = null === $input->scopeId ? null : $this->scopes->findOneVisibleTo($input->scopeId, $actor);
        $owner = null === $input->ownerId ? null : $this->users->findOneVisibleTo($input->ownerId, $actor);
        $relatedAssets = $this->assets->findAllVisibleByIds(array_values(array_unique($input->relatedAssetIds)), $actor);
        if (null === $scope || (null !== $input->ownerId && null === $owner) || count($relatedAssets) !== count(array_unique($input->relatedAssetIds))) {
            return new JsonResponse(['code' => 'INVALID_RELATION', 'message' => 'Le périmètre, le responsable ou un actif lié est invalide.'], 422);
        }
        $created = null === $asset;
        $asset ??= new Asset($input->name, $input->type, $scope, $actor->getOrganization());
        if (in_array($asset, $relatedAssets, true)) {
            return new JsonResponse(['code' => 'INVALID_RELATION', 'message' => 'Un actif ne peut pas être lié à lui-même.'], 422);
        }
        $asset->setName($input->name)->setDescription($input->description)->setType($input->type)->setCriticality($input->criticality)->setConfidentiality($input->confidentiality)->setIntegrity($input->integrity)->setAvailability($input->availability)->setOwner($owner)->setScope($scope)->replaceRelatedAssets($relatedAssets)->setStatus($input->status);
        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        return new JsonResponse($this->responses->asset($asset), $created ? 201 : 200);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Actif introuvable.'], 404);
    }
}
