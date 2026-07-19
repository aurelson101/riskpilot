<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\OrganizationMailer;
use App\Entity\AuthSession;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\AuthSessionRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use App\Security\SecretCipher;
use App\Security\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
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
        private AuthSessionRepository $sessions,
        private PasswordResetTokenRepository $passwordResetTokens,
        private OrganizationMailer $mailer,
        private LoggerInterface $logger,
        private string $appUrl,
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
        if ($user instanceof User && $user->isTemporarilyLocked()) {
            return new JsonResponse(['code' => 'ACCOUNT_TEMPORARILY_LOCKED', 'message' => 'Compte temporairement verrouillé après plusieurs échecs.'], JsonResponse::HTTP_TOO_MANY_REQUESTS);
        }
        if (!$user instanceof User || User::STATUS_ACTIVE !== $user->getStatus() || !$this->passwordHasher->isPasswordValid($user, $password)) {
            if ($user instanceof User && User::STATUS_ACTIVE === $user->getStatus()) {
                $user->registerFailedLogin();
                $this->entityManager->flush();
            }

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

        return $this->createSessionResponse($user, $request);
    }

    #[Route('/api/auth/refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $rawToken = (string) $request->cookies->get('riskpilot_refresh', '');
        $session = '' === $rawToken ? null : $this->sessions->findByRefreshToken($rawToken);
        if (!$session instanceof AuthSession || !$session->isActive()) {
            return $this->clearRefreshCookie(new JsonResponse(['code' => 'INVALID_SESSION', 'message' => 'La session a expiré.'], JsonResponse::HTTP_UNAUTHORIZED));
        }

        $newRefreshToken = $this->randomToken();
        $session->rotate(hash('sha256', $newRefreshToken));
        $this->entityManager->flush();

        return $this->withRefreshCookie(new JsonResponse([
            'token' => $this->tokens->createFromPayload($session->getUser(), ['sid' => $session->getPublicId(), 'jti' => bin2hex(random_bytes(16))]),
        ]), $newRefreshToken);
    }

    #[Route('/api/auth/logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $rawToken = (string) $request->cookies->get('riskpilot_refresh', '');
        $session = '' === $rawToken ? null : $this->sessions->findByRefreshToken($rawToken);
        if ($session instanceof AuthSession) {
            $session->revoke();
            $this->entityManager->flush();
        }

        return $this->clearRefreshCookie(new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT));
    }

    #[Route('/api/auth/forgot-password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $email = mb_strtolower(trim((string) ($request->toArray()['email'] ?? '')));
        $limit = $this->loginLimiter->create('password-reset|'.($request->getClientIp() ?? 'unknown').'|'.$email)->consume();
        if (!$limit->isAccepted()) {
            return new JsonResponse(['code' => 'TOO_MANY_ATTEMPTS', 'message' => 'Trop de demandes. Réessayez plus tard.'], JsonResponse::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $this->users->findOneBy(['email' => $email, 'status' => User::STATUS_ACTIVE]);
        if ($user instanceof User) {
            $this->passwordResetTokens->invalidateFor($user);
            $rawToken = $this->randomToken();
            $this->entityManager->persist(new PasswordResetToken($user, hash('sha256', $rawToken)));
            $this->entityManager->flush();
            try {
                $this->mailer->send(
                    (int) $user->getOrganization()->getId(),
                    $user->getEmail(),
                    'Réinitialisation de votre mot de passe RiskPilot',
                    "Une réinitialisation de votre mot de passe a été demandée.\n\n".rtrim($this->appUrl, '/').'/reset-password?token='.rawurlencode($rawToken)."\n\nCe lien expire dans 30 minutes. Ignorez ce message si vous n’êtes pas à l’origine de la demande.",
                );
            } catch (\Throwable $error) {
                $this->logger->error('Impossible d’envoyer l’email de récupération.', ['exception' => $error, 'userId' => $user->getId()]);
            }
        }

        return new JsonResponse(['message' => 'Si ce compte existe, un email de réinitialisation a été envoyé.']);
    }

    #[Route('/api/auth/reset-password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $input = $request->toArray();
        $rawToken = trim((string) ($input['token'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $reset = '' === $rawToken ? null : $this->passwordResetTokens->findByRawToken($rawToken);
        if (!$reset instanceof PasswordResetToken || !$reset->isValid()) {
            return new JsonResponse(['code' => 'INVALID_RESET_TOKEN', 'message' => 'Ce lien est invalide ou expiré.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (mb_strlen($password) < 12 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) {
            return new JsonResponse(['code' => 'WEAK_PASSWORD', 'message' => 'Le mot de passe doit contenir au moins 12 caractères, une majuscule, une minuscule et un chiffre.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $reset->getUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $reset->consume();
        $this->sessions->revokeAll($user);
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Votre mot de passe a été réinitialisé.']);
    }

    private function createSessionResponse(User $user, Request $request): JsonResponse
    {
        $refreshToken = $this->randomToken();
        $session = new AuthSession(
            $user,
            hash('sha256', $refreshToken),
            (string) $request->headers->get('User-Agent', 'Appareil inconnu'),
            $request->getClientIp() ?? 'unknown',
        );
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $this->withRefreshCookie(new JsonResponse([
            'token' => $this->tokens->createFromPayload($user, ['sid' => $session->getPublicId(), 'jti' => bin2hex(random_bytes(16))]),
        ]), $refreshToken);
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    private function withRefreshCookie(JsonResponse $response, string $token): JsonResponse
    {
        $response->headers->setCookie(Cookie::create('riskpilot_refresh', $token)
            ->withExpires(new \DateTimeImmutable('+30 days'))
            ->withPath('/api')
            ->withSecure(str_starts_with($this->appUrl, 'https://'))
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT));

        return $response;
    }

    private function clearRefreshCookie(JsonResponse $response): JsonResponse
    {
        $response->headers->clearCookie('riskpilot_refresh', '/api', null, str_starts_with($this->appUrl, 'https://'), true, Cookie::SAMESITE_STRICT);

        return $response;
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
