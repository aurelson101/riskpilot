<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\AiInteraction;
use App\Entity\AiSettings;
use App\Entity\User;
use App\Repository\AiInteractionRepository;
use App\Repository\AiSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Point de passage unique des appels au copilote : droits, quotas, budget, journalisation et trace ISO/IEC 42001.
 */
final readonly class AiCopilotRuntime
{
    /** Version de l'avis affiché à l'utilisateur à chaque envoi consenti. */
    public const CONSENT_VERSION = '2026-11';
    /** Rôles autorisés à transmettre des données au fournisseur IA ; un simple lecteur en est exclu. */
    private const SENDER_ROLES = [User::ROLE_RISK_MANAGER, User::ROLE_ACTION_OWNER, User::ROLE_AUDITOR];

    public function __construct(
        private AiSettingsRepository $settings,
        private AiInteractionRepository $interactions,
        private EntityManagerInterface $entityManager,
        private AuthorizationCheckerInterface $authorization,
        private RateLimiterFactoryInterface $aiCopilotLimiter,
        private LoggerInterface $logger,
        private string $appSecret,
    ) {
    }

    public function canSend(): bool
    {
        foreach (self::SENDER_ROLES as $role) {
            if ($this->authorization->isGranted($role)) {
                return true;
            }
        }

        return false;
    }

    /** Le contexte détaillé (noms, commentaires, preuves) n'est envoyé que pour un gestionnaire des risques. */
    public function effectiveDataPolicy(AiSettings $settings): string
    {
        return 'CONTEXTUAL' === $settings->getDataPolicy() && $this->authorization->isGranted(User::ROLE_RISK_MANAGER) ? 'CONTEXTUAL' : 'MINIMAL';
    }

    public function settingsFor(User $actor): ?AiSettings
    {
        return $this->settings->findOneBy(['organization' => $actor->getOrganization()]);
    }

    public function isUsable(?AiSettings $settings): bool
    {
        return $settings instanceof AiSettings && $settings->isEnabled() && null !== $settings->getEncryptedApiKey()
            && $settings->termsAcknowledged() && in_array($settings->getProvider(), AiSettings::PROVIDERS, true);
    }

    /** Refus initial commun : rôle insuffisant ou copilote indisponible. */
    public function unavailable(?AiSettings $settings): ?JsonResponse
    {
        if (!$this->canSend()) {
            return self::error('AI_ROLE_REQUIRED', 'Votre rôle ne permet pas de transmettre des données au copilote IA.', 403);
        }
        if (!$this->isUsable($settings)) {
            return self::error('AI_DISABLED', 'Le copilote IA n’est pas configuré et activé pour cette organisation.', 409);
        }

        return null;
    }

    /**
     * Applique quota utilisateur, plafond journalier et budget mensuel de l'organisation, puis exécute et trace l'appel.
     *
     * @template T
     *
     * @param callable(AiCallMetrics): T $call
     *
     * @return array{result: T, interaction: AiInteraction}|JsonResponse
     */
    public function run(User $actor, AiSettings $settings, string $workflow, string $input, callable $call, string $failureMessage): array|JsonResponse
    {
        $organization = $actor->getOrganization();
        $interaction = new AiInteraction($organization, $actor, $workflow, $settings, $this->effectiveDataPolicy($settings), AiCopilotClient::PROMPT_VERSION, hash('sha256', $input), self::CONSENT_VERSION);
        $today = new \DateTimeImmutable('today');
        $month = new \DateTimeImmutable('first day of this month 00:00');
        if (!$this->aiCopilotLimiter->create(sprintf('%d|%d', $organization->getId(), $actor->getId()))->consume()->isAccepted()) {
            return $this->refuse($interaction, 'RATE_LIMITED', 'AI_RATE_LIMIT', 'Quota du copilote atteint. Réessayez plus tard.');
        }
        if ($this->interactions->providerCallsSince($organization, $today) >= $settings->getDailyCallLimit()) {
            return $this->refuse($interaction, 'RATE_LIMITED', 'AI_DAILY_LIMIT', 'Le plafond journalier d’appels IA de l’organisation est atteint.');
        }
        $usage = $this->interactions->usageSince($organization, $month);
        if ($usage['inputTokens'] + $usage['outputTokens'] >= $settings->getMonthlyTokenBudget()) {
            return $this->refuse($interaction, 'BUDGET_EXCEEDED', 'AI_BUDGET_EXCEEDED', 'Le budget mensuel de tokens IA de l’organisation est épuisé.');
        }

        $metrics = new AiCallMetrics();
        $started = hrtime(true);
        try {
            $result = $call($metrics);
        } catch (\Throwable $error) {
            $interaction->complete('ERROR', null, $this->errorCode($error, $metrics), $this->elapsedMs($started), $metrics);
            $this->persist($interaction);
            $this->logger->warning('AI copilot call failed.', [
                'workflow' => $workflow, 'provider' => $settings->getProvider(), 'model' => $settings->getModel(),
                'organization' => $organization->getId(), 'interaction' => $interaction->getId(),
                'errorClass' => $error::class, 'providerStatus' => $metrics->providerStatus, 'attempts' => $metrics->attempts,
            ]);

            return self::error('AI_PROVIDER_FAILED', $failureMessage, 502, $interaction);
        }
        $interaction->complete('OK', hash('sha256', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), null, $this->elapsedMs($started), $metrics);
        $this->persist($interaction);

        return ['result' => $result, 'interaction' => $interaction];
    }

    /** Signe une réponse de l'assistant pour qu'elle puisse être rejouée dans l'historique sans être falsifiée. */
    public function sign(User $actor, string $content): string
    {
        return hash_hmac('sha256', sprintf('ai-history|%d|%d|%s', $actor->getOrganization()->getId(), $actor->getId(), $content), $this->appSecret);
    }

    /**
     * Valide l'historique client : au plus huit messages, et seuls les tours assistant signés par le serveur sont conservés.
     *
     * @param array<array-key, mixed> $input
     *
     * @return list<array{role: 'user'|'assistant', content: string}>|null
     */
    public function history(User $actor, array $input): ?array
    {
        if (count($input) > 8) {
            return null;
        }
        $history = [];
        foreach ($input as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, ['user', 'assistant'], true) || !is_string($message['content'] ?? null)) {
                return null;
            }
            $content = trim($message['content']);
            if ('' === $content || mb_strlen($content) > 12000 || ('user' === $message['role'] && mb_strlen($content) > 2000)) {
                return null;
            }
            if ('assistant' === $message['role']
                && (!is_string($message['signature'] ?? null) || !hash_equals($this->sign($actor, $content), $message['signature']))) {
                continue;
            }
            $history[] = ['role' => $message['role'], 'content' => $content];
        }

        return $history;
    }

    public static function safetyIdentifier(User $actor): string
    {
        return hash('sha256', sprintf('riskpilot|%d|%d', $actor->getOrganization()->getId(), $actor->getId()));
    }

    public static function error(string $code, string $message, int $status, ?AiInteraction $interaction = null): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'message' => $message] + (null === $interaction ? [] : ['interactionId' => $interaction->getId()]), $status);
    }

    private function refuse(AiInteraction $interaction, string $status, string $code, string $message): JsonResponse
    {
        $interaction->complete($status, null, $code, 0, null);
        $this->persist($interaction);

        return self::error($code, $message, 429, $interaction);
    }

    private function persist(AiInteraction $interaction): void
    {
        $this->entityManager->persist($interaction);
        $this->entityManager->flush();
    }

    private function errorCode(\Throwable $error, AiCallMetrics $metrics): string
    {
        if (null !== $metrics->providerStatus && ($metrics->providerStatus < 200 || $metrics->providerStatus >= 300)) {
            return 'PROVIDER_HTTP_'.$metrics->providerStatus;
        }
        if ($error instanceof \InvalidArgumentException) {
            return 'INVALID_INPUT';
        }

        return null === $metrics->providerStatus ? 'PROVIDER_UNREACHABLE' : 'INVALID_PROVIDER_OUTPUT';
    }

    private function elapsedMs(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
