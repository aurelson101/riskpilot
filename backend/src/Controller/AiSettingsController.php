<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AiCallMetrics;
use App\Application\AiCopilotClient;
use App\Entity\AiSettings;
use App\Entity\User;
use App\Repository\AiInteractionRepository;
use App\Repository\AiSettingsRepository;
use App\Security\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/settings/ai')]
final readonly class AiSettingsController
{
    private const PRESETS = [
        'MISTRAL' => ['baseUrl' => 'https://api.mistral.ai/v1', 'model' => 'mistral-large-latest'],
        'OPENAI' => ['baseUrl' => 'https://api.openai.com/v1', 'model' => 'gpt-5-mini'],
        'GEMINI' => ['baseUrl' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-3.5-flash-lite'],
    ];

    /** Modèles acceptés côté serveur : toute valeur hors liste est refusée, quel que soit le fournisseur. */
    public const MODELS = [
        'MISTRAL' => ['codestral-latest', 'mistral-large-latest', 'mistral-medium-latest', 'mistral-medium-3-5', 'mistral-small-latest', 'mistral-small-2603', 'ministral-14b-2512'],
        'OPENAI' => ['gpt-5-mini', 'gpt-4.1-mini', 'gpt-4.1'],
        'GEMINI' => ['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro'],
    ];

    public function __construct(
        private Security $security,
        private AiSettingsRepository $repository,
        private EntityManagerInterface $entityManager,
        private SecretCipher $cipher,
        private AiCopilotClient $copilot,
        private AiInteractionRepository $interactions,
        private RateLimiterFactoryInterface $aiSettingsTestLimiter,
        private \App\Application\AiSystemRegistry $registry,
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
        foreach (['monthlyTokenBudget' => [10000, 50000000], 'dailyCallLimit' => [1, 100000]] as $field => [$min, $max]) {
            if (array_key_exists($field, $input) && (!is_int($input[$field]) || $input[$field] < $min || $input[$field] > $max)) {
                return $this->error(sprintf('Le champ %s doit être un entier entre %d et %d.', $field, $min, $max));
            }
        }
        if (array_key_exists('acknowledgeTerms', $input) && !is_bool($input['acknowledgeTerms'])) {
            return $this->error('L’acquittement de l’avis d’usage doit être un booléen.');
        }
        $apiKey = trim($input['apiKey'] ?? '');
        if (strlen($input['apiKey'] ?? '') > 4096 || preg_match('/[\x00-\x20\x7F]/', $apiKey)) {
            return $this->error('La clé API est invalide : au maximum 4 096 caractères, sans espace ni caractère de contrôle.');
        }
        $provider = strtoupper(trim($input['provider'] ?? ''));
        if (!in_array($provider, AiSettings::PROVIDERS, true)) {
            return $this->error('Le fournisseur IA sélectionné est invalide.');
        }
        $preset = self::PRESETS[$provider];
        $baseUrl = $preset['baseUrl'];
        $model = trim($input['model'] ?? $preset['model']);
        $dataPolicy = strtoupper(trim($input['dataPolicy'] ?? 'MINIMAL'));
        $systemPrompt = trim($input['systemPrompt'] ?? '');
        $enabled = $input['enabled'] ?? false;
        if (!$this->validHttpsUrl($baseUrl) || '' === $model || mb_strlen($model) > 120 || !in_array($dataPolicy, AiSettings::DATA_POLICIES, true) || mb_strlen($systemPrompt) > 4000) {
            return $this->error('Vérifiez l’URL HTTPS, le modèle et la politique de données.');
        }
        if (!in_array($model, self::MODELS[$provider], true)) {
            return $this->error('Sélectionnez un modèle pris en charge pour ce fournisseur : '.implode(', ', self::MODELS[$provider]).'.');
        }
        $settings = $this->repository->findOneBy(['organization' => $user->getOrganization()]) ?? new AiSettings($user->getOrganization());
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
        if (($input['acknowledgeTerms'] ?? false) === true) {
            $settings->acknowledgeTerms($user);
        }
        if ($enabled && !$settings->termsAcknowledged()) {
            return $this->error('Acquittez l’avis d’usage IA (DPA, transferts et sous-traitant) avant d’activer le copilote.');
        }
        $settings->configure($provider, $baseUrl, $model, $dataPolicy, $systemPrompt, $enabled);
        $settings->setLimits($input['monthlyTokenBudget'] ?? $settings->getMonthlyTokenBudget(), $input['dailyCallLimit'] ?? $settings->getDailyCallLimit());
        $this->entityManager->persist($settings);
        $this->registry->syncCopilot($settings, $user);
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
        if (!$this->aiSettingsTestLimiter->create('org-'.$settings->getOrganization()->getId())->consume()->isAccepted()) {
            return new JsonResponse(['code' => 'AI_RATE_LIMIT', 'message' => 'Trop de tests de connexion. Réessayez dans une minute.'], JsonResponse::HTTP_TOO_MANY_REQUESTS);
        }
        try {
            $this->copilot->askGlobal(
                $settings,
                'Répondez uniquement par OK.',
                [],
                'fr',
                hash('sha256', 'settings-test:'.$settings->getOrganization()->getId()),
                [],
                new AiCallMetrics(),
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

    #[Route('/usage', methods: ['GET'])]
    public function usage(): JsonResponse
    {
        $user = $this->admin();
        $organization = $user->getOrganization();
        $month = new \DateTimeImmutable('first day of this month 00:00');
        $settings = $this->repository->findOneBy(['organization' => $organization]);

        return new JsonResponse([
            'since' => $month->format(DATE_ATOM),
            'usage' => $this->interactions->usageSince($organization, $month),
            'monthlyTokenBudget' => $settings?->getMonthlyTokenBudget() ?? AiSettings::DEFAULT_MONTHLY_TOKEN_BUDGET,
            'dailyCallLimit' => $settings?->getDailyCallLimit() ?? AiSettings::DEFAULT_DAILY_CALL_LIMIT,
            'dailyCalls' => $this->interactions->providerCallsSince($organization, new \DateTimeImmutable('today')),
            'promptVersion' => AiCopilotClient::PROMPT_VERSION,
        ]);
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
        return ['provider' => $settings->getProvider(), 'baseUrl' => $settings->getBaseUrl(), 'model' => $settings->getModel(), 'apiKeyConfigured' => null !== $settings->getEncryptedApiKey(), 'dataPolicy' => $settings->getDataPolicy(), 'systemPrompt' => $settings->getSystemPrompt(), 'enabled' => $settings->isEnabled(), 'monthlyTokenBudget' => $settings->getMonthlyTokenBudget(), 'dailyCallLimit' => $settings->getDailyCallLimit(), 'termsAcknowledged' => $settings->termsAcknowledged(), 'termsVersion' => AiSettings::TERMS_VERSION, 'termsAcknowledgedAt' => $settings->getTermsAcknowledgedAt()?->format(DATE_ATOM), 'models' => self::MODELS[$settings->getProvider()] ?? [], 'updatedAt' => $settings->getUpdatedAt()->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return ['provider' => 'MISTRAL', ...self::PRESETS['MISTRAL'], 'apiKeyConfigured' => false, 'dataPolicy' => 'MINIMAL', 'systemPrompt' => '', 'enabled' => false, 'monthlyTokenBudget' => AiSettings::DEFAULT_MONTHLY_TOKEN_BUDGET, 'dailyCallLimit' => AiSettings::DEFAULT_DAILY_CALL_LIMIT, 'termsAcknowledged' => false, 'termsVersion' => AiSettings::TERMS_VERSION, 'termsAcknowledgedAt' => null, 'models' => self::MODELS['MISTRAL'], 'updatedAt' => null];
    }

    private function error(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_AI_SETTINGS', 'message' => $message], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }
}
