<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\SecretCipher;
use App\Security\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AuthController
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private JWTTokenManagerInterface $tokens,
        private TotpService $totp,
        private SecretCipher $cipher,
        private EntityManagerInterface $entityManager,
        private RateLimiterFactory $loginLimiter,
    ) {
    }

    #[Route('/api/auth/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $input = $request->toArray();
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $limiter = $this->loginLimiter->create(($request->getClientIp() ?? 'unknown').'|'.$email);
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            return new JsonResponse(['code' => 'TOO_MANY_LOGIN_ATTEMPTS', 'message' => 'Trop de tentatives. Réessayez dans une minute.'], JsonResponse::HTTP_TOO_MANY_REQUESTS);
        }
        $password = (string) ($input['password'] ?? '');
        $user = $this->users->findOneBy(['email' => $email]);
        if (!$user instanceof User || User::STATUS_ACTIVE !== $user->getStatus() || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return new JsonResponse(['code' => 'INVALID_CREDENTIALS', 'message' => 'Identifiants invalides.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        if ($user->isMfaEnabled()) {
            $code = trim((string) ($input['mfaCode'] ?? ''));
            if ('' === $code) {
                return new JsonResponse(['mfaRequired' => true, 'message' => 'Un code MFA est requis.'], JsonResponse::HTTP_ACCEPTED);
            }
            if (!$this->validateSecondFactor($user, $code)) {
                return new JsonResponse(['code' => 'INVALID_MFA_CODE', 'message' => 'Code MFA ou code de secours invalide.'], JsonResponse::HTTP_UNAUTHORIZED);
            }
        }

        $user->markLogin();
        $this->entityManager->flush();
        $limiter->reset();

        return new JsonResponse(['token' => $this->tokens->create($user)]);
    }

    private function validateSecondFactor(User $user, string $code): bool
    {
        $secret = $user->getMfaSecret();
        if (null !== $secret && $this->totp->verify($this->cipher->decrypt($secret), $code)) {
            return true;
        }

        $normalized = strtoupper(trim($code));
        $remaining = $user->getMfaRecoveryCodes();
        foreach ($remaining as $index => $hash) {
            if (password_verify($normalized, $hash)) {
                unset($remaining[$index]);
                $user->setMfaRecoveryCodes(array_values($remaining));

                return true;
            }
        }

        return false;
    }
}
