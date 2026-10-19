<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AiCopilotClient;
use App\Entity\AiSettings;
use App\Entity\User;
use App\Repository\AiSettingsRepository;
use App\Security\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/settings/ai')]
final readonly class AiSettingsController
{
    private const PRESETS = [
        'MISTRAL' => ['baseUrl' => 'https://api.mistral.ai/v1', 'model' => 'mistral-large-latest'],
        'OPENAI' => ['baseUrl' => 'https://api.openai.com/v1', 'model' => 'gpt-5-mini'],
        'GEMINI' => ['baseUrl' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-3.5-flash-lite'],
    ];

    private const MISTRAL_MODELS = ['codestral-latest', 'mistral-large-latest', 'mistral-medium-latest', 'mistral-medium-3-5', 'mistral-small-latest', 'mistral-small-2603', 'ministral-14b-2512'];

    public function __construct(
        private Security $security,
        private AiSettingsRepository $repository,
        private EntityManagerInterface $entityManager,
        private SecretCipher $cipher,
        private AiCopilotClient $copilot,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $settings = $this->find();

        return new JsonResponse(null === $settings ? $this->defaults() : $this->serialize($settings));
    }

    #[Route('', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $user = $this->admin();
        $input = $request->toArray();
        foreach (['provider', 'baseUrl', 'model', 'dataPolicy', 'systemPrompt', 'apiKey'] as $field) {
            if (array_key_exists($field, $input) && !is_string($input[$field])) {
                return $this->error('Les paramètres IA textuels sont invalides.');
            }
        }
        if (array_key_exists('enabled', $input) && !is_bool($input['enabled'])) {
            return $this->error('L’activation de l’IA doit être un booléen.');
        }
        $provider = strtoupper(trim($input['provider'] ?? ''));
        if (!in_array($provider, AiSettings::PROVIDERS, true)) {
            return $this->error('Le fournisseur IA sélectionné est invalide.');
        }
        $preset = self::PRESETS[$provider] ?? null;
        $baseUrl = rtrim(trim($preset['baseUrl'] ?? $input['baseUrl'] ?? ''), '/');
        $model = trim($input['model'] ?? $preset['model'] ?? '');
        $dataPolicy = strtoupper(trim($input['dataPolicy'] ?? 'MINIMAL'));
        $systemPrompt = trim($input['systemPrompt'] ?? '');
        $enabled = $input['enabled'] ?? false;
        if (!$this->validHttpsUrl($baseUrl) || '' === $model || mb_strlen($model) > 120 || !in_array($dataPolicy, AiSettings::DATA_POLICIES, true) || mb_strlen($systemPrompt) > 4000) {
            return $this->error('Vérifiez l’URL HTTPS, le modèle et la politique de données.');
        }
        if ('MISTRAL' === $provider && !in_array($model, self::MISTRAL_MODELS, true)) {
            return $this->error('Sélectionnez un modèle Mistral pris en charge : '.implode(', ', self::MISTRAL_MODELS).'.');
        }
        if ($enabled && 'CUSTOM' === $provider) {
            return $this->error('Les endpoints personnalisés restent désactivés tant que leur sécurité réseau n’est pas validée.');
        }
        $settings = $this->repository->findOneBy(['organization' => $user->getOrganization()]) ?? new AiSettings($user->getOrganization());
        $apiKey = trim($input['apiKey'] ?? '');
        if ('' === $apiKey && null !== $settings->getEncryptedApiKey()
            && ($provider !== $settings->getProvider() || $baseUrl !== $settings->getBaseUrl())) {
            return $this->error('Saisissez une nouvelle clé API pour changer de fournisseur ou d’URL.');
        }
        if ('' !== $apiKey) {
            $settings->setEncryptedApiKey($this->cipher->encrypt($apiKey));
        }
        if ($enabled && null === $settings->getEncryptedApiKey()) {
            return $this->error('Une clé API est obligatoire pour activer le copilote.');
        }
        $settings->configure($provider, $baseUrl, $model, $dataPolicy, $systemPrompt, $enabled);
        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($settings));
    }

    #[Route('/test', methods: ['POST'])]
    public function test(): JsonResponse
    {
        $settings = $this->find();
        if (!$settings instanceof AiSettings || null === $settings->getEncryptedApiKey()) {
            return $this->error('Enregistrez une clé API avant de tester la connexion.');
        }
        if ('CUSTOM' === $settings->getProvider()) {
            return $this->error('Le test automatique est désactivé pour les endpoints personnalisés afin d’éviter les accès réseau internes.');
        }
        try {
            $this->copilot->askGlobal(
                $settings,
                'Répondez uniquement par OK.',
                [],
                'fr',
                hash('sha256', 'settings-test:'.$settings->getOrganization()->getId()),
            );
        } catch (\Throwable) {
            return new JsonResponse(['code' => 'AI_GENERATION_FAILED', 'message' => 'Le fournisseur n’a pas pu générer de réponse. Vérifiez la clé, le modèle, le quota et les restrictions réseau.'], JsonResponse::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['message' => 'Connexion et génération IA validées.']);
    }

    private function find(): ?AiSettings
    {
        $user = $this->admin();

        return $this->repository->findOneBy(['organization' => $user->getOrganization()]);
    }

    private function admin(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted(User::ROLE_ADMIN)) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }

    private function validHttpsUrl(string $url): bool
    {
        return false !== filter_var($url, FILTER_VALIDATE_URL) && 'https' === parse_url($url, PHP_URL_SCHEME);
    }

    /** @return array<string, mixed> */
    private function serialize(AiSettings $settings): array
    {
        return ['provider' => $settings->getProvider(), 'baseUrl' => $settings->getBaseUrl(), 'model' => $settings->getModel(), 'apiKeyConfigured' => null !== $settings->getEncryptedApiKey(), 'dataPolicy' => $settings->getDataPolicy(), 'systemPrompt' => $settings->getSystemPrompt(), 'enabled' => $settings->isEnabled(), 'updatedAt' => $settings->getUpdatedAt()->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return ['provider' => 'MISTRAL', ...self::PRESETS['MISTRAL'], 'apiKeyConfigured' => false, 'dataPolicy' => 'MINIMAL', 'systemPrompt' => '', 'enabled' => false, 'updatedAt' => null];
    }

    private function error(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_AI_SETTINGS', 'message' => $message], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }
}
