<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\OauthMailProvider;
use App\Application\OrganizationMailer;
use App\Entity\EmailSettings;
use App\Entity\User;
use App\Repository\EmailSettingsRepository;
use App\Security\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/settings/email')]
final readonly class EmailSettingsController
{
    private const PRESETS = [
        'SMTP2GO' => ['host' => 'mail.smtp2go.com', 'port' => 587, 'encryption' => 'tls'],
    ];

    public function __construct(
        private Security $security,
        private EmailSettingsRepository $repository,
        private EntityManagerInterface $entityManager,
        private SecretCipher $cipher,
        private OrganizationMailer $mailer,
        private OauthMailProvider $oauth,
        private string $appUrl,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $user = $this->admin();
        $settings = $this->repository->findOneBy(['organization' => $user->getOrganization()]);

        return new JsonResponse(null === $settings ? $this->defaults() : $this->serialize($settings));
    }

    #[Route('', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $user = $this->admin();
        $input = $request->toArray();
        $provider = strtoupper((string) ($input['provider'] ?? ''));
        if (!in_array($provider, EmailSettings::PROVIDERS, true)) {
            return $this->error('Le fournisseur sélectionné est invalide.');
        }
        if (in_array($provider, ['GOOGLE_WORKSPACE', 'MICROSOFT_365'], true)) {
            return $this->updateOauth($user, $provider, $input);
        }
        $preset = self::PRESETS[$provider] ?? null;
        $host = trim((string) ($preset['host'] ?? $input['host'] ?? ''));
        $port = (int) ($preset['port'] ?? $input['port'] ?? 0);
        $encryption = (string) ($preset['encryption'] ?? $input['encryption'] ?? 'tls');
        $username = trim((string) ($input['username'] ?? ''));
        $senderEmail = mb_strtolower(trim((string) ($input['senderEmail'] ?? '')));
        $senderName = trim((string) ($input['senderName'] ?? 'RiskPilot'));
        $replyTo = isset($input['replyTo']) ? trim((string) $input['replyTo']) : null;
        if ('' === $host || $port < 1 || $port > 65535 || !in_array($encryption, ['tls', 'ssl', 'none'], true) || '' === $username || false === filter_var($senderEmail, FILTER_VALIDATE_EMAIL) || (null !== $replyTo && '' !== $replyTo && false === filter_var($replyTo, FILTER_VALIDATE_EMAIL))) {
            return $this->error('Vérifiez le serveur, le compte SMTP et les adresses email.');
        }
        $settings = $this->repository->findOneBy(['organization' => $user->getOrganization()]) ?? new EmailSettings($user->getOrganization());
        $password = (string) ($input['password'] ?? '');
        if ('' !== $password) {
            $settings->setEncryptedPassword($this->cipher->encrypt($password));
        }
        if (null === $settings->getEncryptedPassword()) {
            return $this->error('Le mot de passe SMTP est obligatoire.');
        }
        $settings->configure($provider, $host, $port, $encryption, $username, $senderEmail, '' === $senderName ? 'RiskPilot' : $senderName, $replyTo, (bool) ($input['enabled'] ?? false));
        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($settings));
    }

    #[Route('/oauth/{provider}/authorize', methods: ['POST'])]
    public function authorize(string $provider): JsonResponse
    {
        $user = $this->admin();
        $provider = strtoupper($provider);
        $settings = $this->repository->findOneBy(['organization' => $user->getOrganization(), 'provider' => $provider]);
        if (!$settings instanceof EmailSettings || null === $settings->getEncryptedOauthClientSecret()) {
            return $this->error('Enregistrez les identifiants OAuth avant la connexion.');
        }
        $state = bin2hex(random_bytes(32));
        $settings->beginOauth($state, new \DateTimeImmutable('+10 minutes'));
        $this->entityManager->flush();
        $redirectUri = rtrim($this->appUrl, '/').'/api/settings/email/oauth/'.strtolower($provider).'/callback';

        return new JsonResponse(['authorizationUrl' => $this->oauth->authorizationUrl($settings, $redirectUri, $state), 'redirectUri' => $redirectUri]);
    }

    #[Route('/oauth/{provider}/callback', methods: ['GET'])]
    public function callback(string $provider, Request $request): RedirectResponse
    {
        $state = (string) $request->query->get('state', '');
        $settings = $this->repository->findOneBy(['oauthStateHash' => hash('sha256', $state), 'provider' => strtoupper($provider)]);
        $frontend = rtrim($this->appUrl, '/').'/administration/email-settings';
        if (!$settings instanceof EmailSettings || !$settings->consumeOauthState($state) || $request->query->has('error')) {
            if ($settings instanceof EmailSettings) {
                $this->entityManager->flush();
            }

            return new RedirectResponse($frontend.'?oauth=error');
        }
        try {
            $redirectUri = rtrim($this->appUrl, '/').$request->getPathInfo();
            $tokens = $this->oauth->exchangeCode($settings, $redirectUri, (string) $request->query->get('code', ''));
            $email = $this->oauth->connectedEmail($settings, $tokens['access_token']);
            $settings->connectOauth($this->cipher->encrypt($tokens['access_token']), isset($tokens['refresh_token']) ? $this->cipher->encrypt($tokens['refresh_token']) : null, new \DateTimeImmutable('+'.max(60, $tokens['expires_in']).' seconds'), $email);
            $this->entityManager->flush();

            return new RedirectResponse($frontend.'?oauth=success');
        } catch (\Throwable) {
            $this->entityManager->flush();

            return new RedirectResponse($frontend.'?oauth=error');
        }
    }

    #[Route('/oauth/disconnect', methods: ['POST'])]
    public function disconnect(): JsonResponse
    {
        $user = $this->admin();
        $settings = $this->repository->findOneBy(['organization' => $user->getOrganization()]);
        if ($settings instanceof EmailSettings) {
            $settings->disconnectOauth();
            $this->entityManager->flush();
        }

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    #[Route('/test', methods: ['POST'])]
    public function test(Request $request): JsonResponse
    {
        $user = $this->admin();
        $recipient = mb_strtolower(trim((string) ($request->toArray()['recipient'] ?? '')));
        if (false === filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Le destinataire de test est invalide.');
        }
        $settings = $this->repository->findOneBy(['organization' => $user->getOrganization()]);
        if (!$settings instanceof EmailSettings) {
            return $this->error('Enregistrez la configuration avant de la tester.');
        }
        try {
            $this->mailer->sendWithSettings($settings, $recipient, 'Test de messagerie RiskPilot', 'Votre configuration de messagerie RiskPilot fonctionne correctement.');
        } catch (\Throwable) {
            return new JsonResponse(['code' => 'SMTP_CONNECTION_FAILED', 'message' => 'Échec de connexion ou d’authentification SMTP. Vérifiez les identifiants et les règles du fournisseur.'], JsonResponse::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['message' => 'Email de test envoyé.']);
    }

    /** @return array<string, mixed> */
    private function serialize(EmailSettings $settings): array
    {
        return ['provider' => $settings->getProvider(), 'host' => $settings->getHost(), 'port' => $settings->getPort(), 'encryption' => $settings->getEncryption(), 'username' => $settings->getUsername(), 'passwordConfigured' => null !== $settings->getEncryptedPassword(), 'senderEmail' => $settings->getSenderEmail(), 'senderName' => $settings->getSenderName(), 'replyTo' => $settings->getReplyTo(), 'enabled' => $settings->isEnabled(), 'oauthClientId' => $settings->getOauthClientId(), 'oauthClientSecretConfigured' => null !== $settings->getEncryptedOauthClientSecret(), 'oauthTenant' => $settings->getOauthTenant(), 'oauthConnected' => null !== $settings->getEncryptedRefreshToken(), 'connectedEmail' => $settings->getConnectedEmail(), 'updatedAt' => $settings->getUpdatedAt()->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return ['provider' => 'SMTP2GO', ...self::PRESETS['SMTP2GO'], 'username' => '', 'passwordConfigured' => false, 'senderEmail' => '', 'senderName' => 'RiskPilot', 'replyTo' => null, 'enabled' => false, 'oauthClientId' => null, 'oauthClientSecretConfigured' => false, 'oauthTenant' => null, 'oauthConnected' => false, 'connectedEmail' => null, 'updatedAt' => null];
    }

    private function error(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_EMAIL_SETTINGS', 'message' => $message], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @param array<string, mixed> $input */
    private function updateOauth(User $user, string $provider, array $input): JsonResponse
    {
        $clientId = trim((string) ($input['oauthClientId'] ?? ''));
        $clientSecret = trim((string) ($input['oauthClientSecret'] ?? ''));
        $tenant = 'MICROSOFT_365' === $provider ? trim((string) ($input['oauthTenant'] ?? 'common')) : null;
        $senderName = trim((string) ($input['senderName'] ?? 'RiskPilot'));
        $replyTo = isset($input['replyTo']) ? trim((string) $input['replyTo']) : null;
        $settings = $this->repository->findOneBy(['organization' => $user->getOrganization()]) ?? new EmailSettings($user->getOrganization());
        $clientSecretRequired = null === $settings->getEncryptedOauthClientSecret() || $settings->getProvider() !== $provider;
        if ('' === $clientId || ('' === $clientSecret && $clientSecretRequired) || (null !== $replyTo && '' !== $replyTo && false === filter_var($replyTo, FILTER_VALIDATE_EMAIL))) {
            return $this->error('Renseignez un client ID, un secret OAuth et une adresse de réponse valide.');
        }
        $settings->configureOauth($provider, $clientId, '' === $clientSecret ? null : $this->cipher->encrypt($clientSecret), $tenant, '' === $senderName ? 'RiskPilot' : $senderName, $replyTo);
        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($settings));
    }

    private function admin(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted(User::ROLE_ADMIN)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }

        return $user;
    }
}
