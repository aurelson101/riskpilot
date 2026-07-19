<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Api\Dto\CreateOrganizationInput;
use App\Api\Dto\UpdateOrganizationInput;
use App\Api\JsonInputMapper;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/organizations')]
#[IsGranted(User::ROLE_ADMIN)]
final readonly class OrganizationController
{
    public function __construct(
        private OrganizationRepository $organizations,
        private EntityManagerInterface $entityManager,
        private Security $security,
        private JsonInputMapper $inputMapper,
        private ApiResponseFactory $responses,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map(
            $this->responses->organization(...),
            $this->organizations->findVisibleTo($this->actor()),
        ));
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $organization = $this->organizations->findOneVisibleTo($id, $this->actor());

        return null === $organization
            ? $this->notFound()
            : new JsonResponse($this->responses->organization($organization));
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted(User::ROLE_SUPER_ADMIN)]
    public function create(Request $request): JsonResponse
    {
        [$input, $violations] = $this->inputMapper->map($request, CreateOrganizationInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }

        $organization = (new Organization($input->name))->setDescription($input->description);
        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        return new JsonResponse($this->responses->organization($organization), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])]
    #[IsGranted(User::ROLE_SUPER_ADMIN)]
    public function update(int $id, Request $request): JsonResponse
    {
        $organization = $this->organizations->find($id);
        if (null === $organization) {
            return $this->notFound();
        }

        [$input, $violations] = $this->inputMapper->map($request, UpdateOrganizationInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }

        $organization
            ->setName($input->name)
            ->setDescription($input->description)
            ->setStatus($input->status);
        $this->entityManager->flush();

        return new JsonResponse($this->responses->organization($organization));
    }

    private function actor(): User
    {
        $actor = $this->security->getUser();
        if (!$actor instanceof User) {
            throw new \LogicException('Authenticated RiskPilot user expected.');
        }

        return $actor;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Organisation introuvable.'], JsonResponse::HTTP_NOT_FOUND);
    }
}
