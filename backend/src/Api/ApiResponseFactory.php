<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Organization;
use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\ConstraintViolationListInterface;

final class ApiResponseFactory
{
    public function validationError(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()][] = $violation->getMessage();
        }

        return new JsonResponse([
            'code' => 'VALIDATION_ERROR',
            'message' => 'La requête contient des erreurs.',
            'errors' => $errors,
        ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @return array<string, mixed> */
    public function organization(Organization $organization): array
    {
        return [
            'id' => $organization->getId(),
            'name' => $organization->getName(),
            'description' => $organization->getDescription(),
            'status' => $organization->getStatus(),
            'createdAt' => $organization->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $organization->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function user(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'roles' => $user->getRoles(),
            'status' => $user->getStatus(),
            'organization' => $this->organization($user->getOrganization()),
            'createdAt' => $user->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $user->getUpdatedAt()->format(DATE_ATOM),
            'lastLoginAt' => $user->getLastLoginAt()?->format(DATE_ATOM),
        ];
    }
}
