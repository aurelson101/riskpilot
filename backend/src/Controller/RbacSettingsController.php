<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\User;
use App\Security\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/settings/rbac')]
#[IsGranted(User::ROLE_ADMIN)]
final readonly class RbacSettingsController
{
    public function __construct(private CurrentUser $currentUser, private PermissionChecker $permissions, private EntityManagerInterface $em)
    {
    }

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return new JsonResponse(['permissions' => PermissionChecker::ALL, 'roles' => $this->permissions->effectiveMatrix($this->currentUser->get())]);
    }

    #[Route('', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $input = $request->toArray();
        $roles = $input['roles'] ?? null;
        if (!is_array($roles)) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => 'La matrice des rôles est obligatoire.'], 422);
        }
        $normalized = [];
        foreach (User::ASSIGNABLE_ROLES as $role) {
            $values = $roles[$role] ?? [];
            if (!is_array($values) || array_filter($values, fn ($value): bool => !is_string($value) || !in_array($value, PermissionChecker::ALL, true))) {
                return new JsonResponse(['code' => 'INVALID_PERMISSION', 'message' => sprintf('Permissions invalides pour %s.', $role)], 422);
            }
            $normalized[$role] = array_values(array_unique($values));
        }
        $organization = $this->currentUser->get()->getOrganization();
        $organization->setRolePermissions($normalized);
        $this->em->flush();

        return $this->show();
    }
}
