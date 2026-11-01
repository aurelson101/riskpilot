<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AiCallMetrics;
use App\Application\AiCopilotClient;
use App\Application\AiCopilotRuntime;
use App\Application\CurrentUser;
use App\Entity\AiSettings;
use App\Entity\ComplianceResult;
use App\Repository\ComplianceResultRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/compliance-results/{id<\d+>}/copilot')]
final readonly class ComplianceCopilotController
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComplianceResultRepository $results,
        private AiCopilotRuntime $runtime,
        private AiCopilotClient $client,
    ) {
    }

    #[Route('/context', methods: ['GET'])]
    public function context(int $id): JsonResponse
    {
        $result = $this->result($id);
        if (!$result instanceof ComplianceResult) {
            return $this->error('NOT_FOUND', 'Résultat de conformité introuvable.', 404);
        }
        $settings = $this->runtime->settingsFor($this->currentUser->get());
        $policy = null === $settings ? 'MINIMAL' : $this->runtime->effectiveDataPolicy($settings);

        return new JsonResponse([
            'enabled' => $this->runtime->canSend() && $this->runtime->isUsable($settings),
            'allowed' => $this->runtime->canSend(),
            'provider' => $settings?->getProvider(),
            'model' => $settings?->getModel(),
            'dataPolicy' => $policy,
            'consentVersion' => AiCopilotRuntime::CONSENT_VERSION,
            'context' => $this->sharedContext($result, $policy),
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
        $settings = $this->runtime->settingsFor($actor);
        if (null !== $refusal = $this->runtime->unavailable($settings)) {
            return $refusal;
        }
        \assert($settings instanceof AiSettings);
        $input = $request->toArray();
        if (!is_string($input['question'] ?? null) || (array_key_exists('history', $input) && !is_array($input['history']))) {
            return $this->error('INVALID_REQUEST', 'La question et l’historique doivent respecter le format attendu.', 422);
        }
        $question = trim($input['question']);
        if (true !== ($input['consent'] ?? false) || mb_strlen($question) < 3 || mb_strlen($question) > 2000) {
            return $this->error('INVALID_REQUEST', 'Le consentement et une question de 3 à 2 000 caractères sont obligatoires.', 422);
        }
        $history = $this->runtime->history($actor, $input['history'] ?? []);
        if (null === $history) {
            return $this->error('INVALID_HISTORY', 'L’historique est invalide ou dépasse huit messages.', 422);
        }
        $dataPolicy = $this->runtime->effectiveDataPolicy($settings);
        $context = $this->sharedContext($result, $dataPolicy);
        $run = $this->runtime->run($actor, $settings, 'COMPLIANCE_COPILOT', $question, fn (AiCallMetrics $metrics): string => $this->client->ask(
            $settings, $context, $question, $history, $actor->getLocale(), AiCopilotRuntime::safetyIdentifier($actor), $metrics,
        ), 'Le fournisseur IA n’a pas pu produire de réponse.');
        if ($run instanceof JsonResponse) {
            return $run;
        }
        $answer = $run['result'];
        $request->attributes->set('_audit_after', [[
            'provider' => $settings->getProvider(),
            'model' => $settings->getModel(),
            'dataPolicy' => $dataPolicy,
            'questionHash' => hash('sha256', $question),
            'interactionId' => $run['interaction']->getId(),
            'complianceResultId' => $result->getId(),
            'sourceIds' => array_column($this->sources($result), 'id'),
            'automaticWrite' => false,
        ]]);

        return new JsonResponse([
            'answer' => $answer,
            'signature' => $this->runtime->sign($actor, $answer),
            'interactionId' => $run['interaction']->getId(),
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

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'message' => $message], $status);
    }
}
