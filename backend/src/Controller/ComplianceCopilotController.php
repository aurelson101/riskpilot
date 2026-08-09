<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AiCopilotClient;
use App\Application\CurrentUser;
use App\Entity\AiSettings;
use App\Entity\ComplianceResult;
use App\Repository\AiSettingsRepository;
use App\Repository\ComplianceResultRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/compliance-results/{id<\d+>}/copilot')]
final readonly class ComplianceCopilotController
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComplianceResultRepository $results,
        private AiSettingsRepository $settings,
        private AiCopilotClient $client,
        private RateLimiterFactory $aiCopilotLimiter,
    ) {
    }

    #[Route('/context', methods: ['GET'])]
    public function context(int $id): JsonResponse
    {
        $result = $this->result($id);
        if (!$result instanceof ComplianceResult) {
            return $this->error('NOT_FOUND', 'Résultat de conformité introuvable.', 404);
        }
        $settings = $this->findSettings();

        return new JsonResponse([
            'enabled' => $settings?->isEnabled() ?? false,
            'provider' => $settings?->getProvider(),
            'model' => $settings?->getModel(),
            'dataPolicy' => $settings?->getDataPolicy() ?? 'MINIMAL',
            'context' => $this->sharedContext($result, $settings?->getDataPolicy() ?? 'MINIMAL'),
            'sources' => $this->sources($result),
            'notice' => 'Aucune réponse IA ne modifie automatiquement la conformité, les preuves ou le score.',
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function ask(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $result = $this->result($id);
        if (!$result instanceof ComplianceResult) {
            return $this->error('NOT_FOUND', 'Résultat de conformité introuvable.', 404);
        }
        $settings = $this->findSettings();
        if (!$settings instanceof AiSettings || !$settings->isEnabled() || null === $settings->getEncryptedApiKey()) {
            return $this->error('AI_DISABLED', 'Le copilote IA n’est pas configuré et activé pour cette organisation.', 409);
        }
        if ('CUSTOM' === $settings->getProvider()) {
            return $this->error('CUSTOM_PROVIDER_UNAVAILABLE', 'Les endpoints personnalisés ne sont pas autorisés pour le chatbot tant que leur sécurité réseau n’est pas validée.', 422);
        }
        $input = $request->toArray();
        $question = trim((string) ($input['question'] ?? ''));
        if (true !== ($input['consent'] ?? false) || mb_strlen($question) < 3 || mb_strlen($question) > 2000) {
            return $this->error('INVALID_REQUEST', 'Le consentement et une question de 3 à 2 000 caractères sont obligatoires.', 422);
        }
        $history = $this->history((array) ($input['history'] ?? []));
        if (null === $history) {
            return $this->error('INVALID_HISTORY', 'L’historique est invalide ou dépasse huit messages.', 422);
        }
        $limit = $this->aiCopilotLimiter->create(sprintf('%d|%d', $actor->getOrganization()->getId(), $actor->getId()))->consume();
        if (!$limit->isAccepted()) {
            return $this->error('AI_RATE_LIMIT', 'Quota du copilote atteint. Réessayez plus tard.', 429);
        }
        $context = $this->sharedContext($result, $settings->getDataPolicy());
        try {
            $answer = $this->client->ask(
                $settings,
                $context,
                $question,
                $history,
                $actor->getLocale(),
                hash('sha256', sprintf('riskpilot|%d|%d', $actor->getOrganization()->getId(), $actor->getId())),
            );
        } catch (\Throwable) {
            return $this->error('AI_PROVIDER_FAILED', 'Le fournisseur IA n’a pas pu produire de réponse.', 502);
        }
        $request->attributes->set('_audit_after', [[
            'provider' => $settings->getProvider(),
            'model' => $settings->getModel(),
            'dataPolicy' => $settings->getDataPolicy(),
            'questionHash' => hash('sha256', $question),
            'complianceResultId' => $result->getId(),
            'sourceIds' => array_column($this->sources($result), 'id'),
            'automaticWrite' => false,
        ]]);

        return new JsonResponse([
            'answer' => $answer,
            'provider' => $settings->getProvider(),
            'model' => $settings->getModel(),
            'sources' => $this->sources($result),
            'automaticWrite' => false,
            'notice' => 'Conseil à vérifier humainement. Aucun champ n’a été modifié.',
        ]);
    }

    private function result(int $id): ?ComplianceResult
    {
        $result = $this->results->find($id);

        return $result instanceof ComplianceResult && $result->getAssessment()->getOrganization() === $this->currentUser->get()->getOrganization() ? $result : null;
    }

    private function findSettings(): ?AiSettings
    {
        return $this->settings->findOneBy(['organization' => $this->currentUser->get()->getOrganization()]);
    }

    /** @return array<string, scalar|array<array-key, scalar|null>|null> */
    private function sharedContext(ComplianceResult $result, string $policy): array
    {
        $requirement = $result->getRequirement();
        $assessment = $result->getAssessment();
        $context = [
            'framework' => $assessment->getFramework()->getName().' '.$assessment->getFramework()->getVersion(),
            'requirementReference' => $requirement->getReference(),
            'requirementTitle' => $requirement->getTitle(),
            'category' => $requirement->getCategory(),
            'maturityLevel' => $result->getMaturityLevel(),
            'complianceStatus' => $result->getComplianceStatus(),
        ];
        if ('CONTEXTUAL' === $policy) {
            $context += [
                'requirementDescription' => $requirement->getDescription(),
                'assessmentScope' => $assessment->getScope()->getName(),
                'currentComment' => $result->getComment(),
                'evidenceReferences' => $result->getEvidence(),
                'remediationAction' => $result->getRemediationAction()?->getTitle(),
            ];
        }

        return $context;
    }

    /** @return list<array{type: string, id: int, label: string}> */
    private function sources(ComplianceResult $result): array
    {
        return [[
            'type' => 'REQUIREMENT',
            'id' => (int) $result->getRequirement()->getId(),
            'label' => $result->getRequirement()->getReference().' — '.$result->getRequirement()->getTitle(),
        ]];
    }

    /** @param array<array-key, mixed> $input
     * @return list<array{role: 'user'|'assistant', content: string}>|null
     */
    private function history(array $input): ?array
    {
        if (count($input) > 8) {
            return null;
        }
        $history = [];
        foreach ($input as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, ['user', 'assistant'], true)) {
                return null;
            }
            $content = trim((string) ($message['content'] ?? ''));
            if ('' === $content || mb_strlen($content) > 2000) {
                return null;
            }
            $history[] = ['role' => $message['role'], 'content' => $content];
        }

        return $history;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'message' => $message], $status);
    }
}
