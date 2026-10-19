<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\SecretCipher;
use App\Security\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/me/mfa')]
final readonly class MfaController
{
    public function __construct(
        private Security $security,
        private TotpService $totp,
        private SecretCipher $cipher,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/setup', methods: ['POST'])]
    public function setup(Request $request): JsonResponse
    {
        $user = $this->actor();
        if ($user->isMfaEnabled()) {
            return new JsonResponse(['code' => 'MFA_ALREADY_ENABLED', 'message' => 'Le MFA est déjà activé.'], JsonResponse::HTTP_CONFLICT);
        }
        if (!$this->validPassword($user, $request)) {
            return $this->invalidPassword();
        }
        $secret = $this->totp->generateSecret();

        return $this->sensitive(new JsonResponse(['secret' => $secret, 'provisioningUri' => $this->totp->provisioningUri($secret, $user->getEmail())]));
    }

    #[Route('/enable', methods: ['POST'])]
    public function enable(Request $request): JsonResponse
    {
        $user = $this->actor();
        $input = $request->toArray();
        if ($user->isMfaEnabled()) {
            return new JsonResponse(['code' => 'MFA_ALREADY_ENABLED', 'message' => 'Le MFA est déjà activé.'], JsonResponse::HTTP_CONFLICT);
        }
        if (!is_string($input['currentPassword'] ?? null) || !is_string($input['secret'] ?? null) || !is_string($input['code'] ?? null)) {
            return new JsonResponse(['code' => 'INVALID_MFA_REQUEST', 'message' => 'Les paramètres MFA sont invalides.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->passwordHasher->isPasswordValid($user, $input['currentPassword'])) {
            return $this->invalidPassword();
        }
        $secret = strtoupper(trim($input['secret']));
        if (!$this->totp->verify($secret, $input['code'])) {
            return new JsonResponse(['code' => 'INVALID_MFA_CODE', 'message' => 'Le code à six chiffres est invalide.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        $codes = $this->totp->generateRecoveryCodes();
        $user->enableMfa($this->cipher->encrypt($secret), array_map(static fn (string $code): string => password_hash($code, PASSWORD_ARGON2ID), $codes));
        $this->entityManager->flush();

        return $this->sensitive(new JsonResponse(['recoveryCodes' => $codes]));
    }

    #[Route('/disable', methods: ['POST'])]
    public function disable(Request $request): JsonResponse
    {
        $user = $this->actor();
        if (!$this->validPassword($user, $request)) {
            return $this->invalidPassword();
        }
        $user->disableMfa();
        $this->entityManager->flush();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function validPassword(User $user, Request $request): bool
    {
        $password = $request->toArray()['currentPassword'] ?? null;

        return is_string($password) && $this->passwordHasher->isPasswordValid($user, $password);
    }

    private function invalidPassword(): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_PASSWORD', 'message' => 'Le mot de passe actuel est incorrect.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function sensitive(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
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
