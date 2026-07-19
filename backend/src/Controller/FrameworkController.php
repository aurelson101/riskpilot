<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Api\Dto\FrameworkInput;
use App\Api\Dto\RequirementInput;
use App\Api\JsonInputMapper;
use App\Entity\Framework;
use App\Entity\Requirement;
use App\Entity\User;
use App\Repository\FrameworkRepository;
use App\Repository\RequirementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class FrameworkController
{
    public function __construct(private FrameworkRepository $frameworks, private RequirementRepository $requirements, private EntityManagerInterface $entityManager, private JsonInputMapper $mapper, private ApiResponseFactory $responses)
    {
    }

    #[Route('/api/frameworks', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->responses->framework(...), $this->frameworks->findAvailable()));
    }

    #[Route('/api/frameworks/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $item = $this->frameworks->find($id);

        return null === $item ? $this->notFound() : new JsonResponse($this->responses->framework($item));
    }

    #[Route('/api/frameworks', methods: ['POST'])] #[IsGranted(User::ROLE_ADMIN)]
    public function create(Request $request): JsonResponse
    {
        return $this->saveFramework(null, $request);
    }

    #[Route('/api/frameworks/{id<\d+>}', methods: ['PUT'])] #[IsGranted(User::ROLE_ADMIN)]
    public function update(int $id, Request $request): JsonResponse
    {
        $item = $this->frameworks->find($id);

        return null === $item ? $this->notFound() : $this->saveFramework($item, $request);
    }

    #[Route('/api/frameworks/{id<\d+>}/requirements', methods: ['GET'])]
    public function requirements(int $id): JsonResponse
    {
        $framework = $this->frameworks->find($id);

        return null === $framework ? $this->notFound() : new JsonResponse(array_map($this->responses->requirement(...), $this->requirements->findForFramework($framework)));
    }

    #[Route('/api/frameworks/{id<\d+>}/requirements', methods: ['POST'])] #[IsGranted(User::ROLE_ADMIN)]
    public function createRequirement(int $id, Request $request): JsonResponse
    {
        $framework = $this->frameworks->find($id);

        return null === $framework ? $this->notFound() : $this->saveRequirement(null, $framework, $request);
    }

    #[Route('/api/requirements/{id<\d+>}', methods: ['PUT'])] #[IsGranted(User::ROLE_ADMIN)]
    public function updateRequirement(int $id, Request $request): JsonResponse
    {
        $item = $this->requirements->find($id);

        return null === $item ? new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Exigence introuvable.'], 404) : $this->saveRequirement($item, $item->getFramework(), $request);
    }

    private function saveFramework(?Framework $item, Request $request): JsonResponse
    {
        [$input, $violations] = $this->mapper->map($request, FrameworkInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        } $created = null === $item;
        $item ??= new Framework($input->name, $input->version);
        $item->setName($input->name)->setVersion($input->version)->setDescription($input->description)->setPublisher($input->publisher)->setStatus($input->status);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return new JsonResponse($this->responses->framework($item), $created ? 201 : 200);
    }

    private function saveRequirement(?Requirement $item, Framework $framework, Request $request): JsonResponse
    {
        [$input, $violations] = $this->mapper->map($request, RequirementInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        } $parent = null === $input->parentRequirementId ? null : $this->requirements->findOneBy(['id' => $input->parentRequirementId, 'framework' => $framework]);
        if (null !== $input->parentRequirementId && null === $parent) {
            return new JsonResponse(['code' => 'INVALID_PARENT', 'message' => 'Exigence parente invalide.'], 422);
        } $created = null === $item;
        $item ??= new Requirement($framework, $input->reference, $input->title, $input->category);
        $item->setReference($input->reference)->setTitle($input->title)->setDescription($input->description)->setCategory($input->category)->setParentRequirement($parent)->setStatus($input->status);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return new JsonResponse($this->responses->requirement($item), $created ? 201 : 200);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Référentiel introuvable.'], 404);
    }
}
