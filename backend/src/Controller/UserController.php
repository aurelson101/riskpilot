<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Api\Dto\CreateUserInput;
use App\Api\Dto\UpdateUserInput;
use App\Api\JsonInputMapper;
use App\Entity\User;
use App\Repository\AuthSessionRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/users')]
final readonly class UserController
{
    public function __construct(
        private UserRepository $users,
        private OrganizationRepository $organizations,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private Security $security,
        private JsonInputMapper $inputMapper,
        private ApiResponseFactory $responses,
        private AuthSessionRepository $sessions,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->responses->user(...), $this->users->findVisibleTo($this->actor())));
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function show(int $id): JsonResponse
    {
        $user = $this->users->findOneVisibleTo($id, $this->actor());

        return null === $user ? $this->notFound() : new JsonResponse($this->responses->user($user));
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function create(Request $request): JsonResponse
    {
        [$input, $violations] = $this->inputMapper->map($request, CreateUserInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }
        if (!$this->rolesAreAllowed($input->roles)) {
            return $this->invalidRoles();
        }
        if (null !== $this->users->findOneBy(['email' => mb_strtolower($input->email)])) {
            return new JsonResponse(['code' => 'EMAIL_ALREADY_USED', 'message' => 'Cette adresse email est déjà utilisée.'], JsonResponse::HTTP_CONFLICT);
        }

        $actor = $this->actor();
        $organization = in_array(User::ROLE_SUPER_ADMIN, $actor->getRoles(), true) && null !== $input->organizationId
            ? $this->organizations->find($input->organizationId)
            : $actor->getOrganization();
        if (null === $organization) {
            return new JsonResponse(['code' => 'ORGANIZATION_NOT_FOUND', 'message' => 'Organisation introuvable.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = new User($input->email, $input->firstName, $input->lastName, $organization, $input->roles);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return new JsonResponse($this->responses->user($user), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->users->findOneVisibleTo($id, $this->actor());
        if (null === $user) {
            return $this->notFound();
        }

        [$input, $violations] = $this->inputMapper->map($request, UpdateUserInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }
        if (!$this->rolesAreAllowed($input->roles)) {
            return $this->invalidRoles();
        }
        $existingUser = $this->users->findOneBy(['email' => mb_strtolower($input->email)]);
        if (null !== $existingUser && $existingUser !== $user) {
            return new JsonResponse(['code' => 'EMAIL_ALREADY_USED', 'message' => 'Cette adresse email est déjà utilisée.'], JsonResponse::HTTP_CONFLICT);
        }

        $user->setEmail($input->email)->setFirstName($input->firstName)->setLastName($input->lastName)->setRoles($input->roles)->setStatus($input->status);
        if (User::STATUS_ACTIVE !== $input->status) {
            $this->sessions->revokeAll($user);
        }
        $this->entityManager->flush();

        return new JsonResponse($this->responses->user($user));
    }

    #[Route('/{id<\d+>}', methods: ['DELETE'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function delete(int $id): JsonResponse
    {
        $actor = $this->actor();
        $user = $this->users->findOneVisibleTo($id, $actor);
        if (null === $user) {
            return $this->notFound();
        }
        if ($user === $actor) {
            return new JsonResponse(['code' => 'SELF_DELETE_FORBIDDEN', 'message' => 'Vous ne pouvez pas supprimer votre propre compte.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Les comptes sont désactivés afin de conserver leurs responsabilités et l’historique métier.
        $user->setStatus(User::STATUS_INACTIVE);
        $this->sessions->revokeAll($user);
        $this->entityManager->flush();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    /** @param list<string> $roles */
    private function rolesAreAllowed(array $roles): bool
    {
        $actor = $this->actor();
        $allowedRoles = in_array(User::ROLE_SUPER_ADMIN, $actor->getRoles(), true)
            ? [...User::ASSIGNABLE_ROLES, User::ROLE_SUPER_ADMIN]
            : User::ASSIGNABLE_ROLES;

        return [] === array_diff($roles, $allowedRoles);
    }

    private function actor(): User
    {
        $actor = $this->security->getUser();
        if (!$actor instanceof User) {
            throw new \LogicException('Authenticated RiskPilot user expected.');
        }

        return $actor;
    }

    private function invalidRoles(): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_ROLES', 'message' => 'Un ou plusieurs rôles ne sont pas autorisés.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Utilisateur introuvable.'], JsonResponse::HTTP_NOT_FOUND);
    }
}
