<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Api\Dto\ChangePasswordInput;
use App\Api\Dto\UpdateProfileInput;
use App\Api\JsonInputMapper;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/me')]
final readonly class ProfileController
{
    public function __construct(
        private Security $security,
        private ApiResponseFactory $responses,
        private JsonInputMapper $inputMapper,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return new JsonResponse($this->responses->user($this->actor()));
    }

    #[Route('', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        [$input, $violations] = $this->inputMapper->map($request, UpdateProfileInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }

        $user = $this->actor();
        $existingUser = $this->users->findOneBy(['email' => mb_strtolower($input->email)]);
        if (null !== $existingUser && $existingUser !== $user) {
            return new JsonResponse([
                'code' => 'EMAIL_ALREADY_USED',
                'message' => 'Cette adresse email est déjà utilisée.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user
            ->setFirstName(trim($input->firstName))
            ->setLastName(trim($input->lastName))
            ->setEmail(trim($input->email));
        $this->entityManager->flush();

        return new JsonResponse($this->responses->user($user));
    }

    #[Route('/password', methods: ['PUT'])]
    public function changePassword(Request $request): JsonResponse
    {
        [$input, $violations] = $this->inputMapper->map($request, ChangePasswordInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }

        $user = $this->actor();
        if (!$this->passwordHasher->isPasswordValid($user, $input->currentPassword)) {
            return new JsonResponse(['code' => 'INVALID_PASSWORD', 'message' => 'Le mot de passe actuel est incorrect.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $input->newPassword));
        $this->entityManager->flush();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function actor(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Authenticated RiskPilot user expected.');
        }

        return $user;
    }
}
