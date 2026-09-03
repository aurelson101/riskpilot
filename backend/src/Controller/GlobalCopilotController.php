<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AiCopilotClient;
use App\Application\CurrentUser;
use App\Entity\AiSettings;
use App\Entity\User;
use App\Repository\AiSettingsRepository;
use App\Repository\AssetRepository;
use App\Repository\ComplianceResultRepository;
use App\Repository\ScopeRepository;
use App\Repository\ThreatRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/copilot')]
final readonly class GlobalCopilotController
{
    public function __construct(
        private CurrentUser $currentUser,
        private AiSettingsRepository $settings,
        private AiCopilotClient $client,
        private RateLimiterFactory $aiCopilotLimiter,
        private ScopeRepository $scopes,
        private AssetRepository $assets,
        private ThreatRepository $threats,
        private ComplianceResultRepository $complianceResults,
    ) {
    }

    #[Route('/compliance-catalog', methods: ['GET'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function complianceCatalog(): JsonResponse
    {
        return new JsonResponse(['items' => $this->complianceCatalogItems()]);
    }

    #[Route('/grc-brief', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function grcBrief(Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $settings = $this->findSettings();
        if (!$settings instanceof AiSettings || !$settings->isEnabled() || null === $settings->getEncryptedApiKey()) {
            return $this->error('AI_DISABLED', 'Le copilote IA n’est pas configuré et activé pour cette organisation.', 409);
        }
        if ('CUSTOM' === $settings->getProvider()) {
            return $this->error('CUSTOM_PROVIDER_UNAVAILABLE', 'Les endpoints personnalisés ne sont pas autorisés pour ce workflow.', 422);
        }
        $input = $request->toArray();
        $objective = trim((string) ($input['objective'] ?? ''));
        if (true !== ($input['consent'] ?? false) || mb_strlen($objective) > 1000) {
            return $this->error('INVALID_REQUEST', 'Le consentement est obligatoire et l’objectif est limité à 1 000 caractères.', 422);
        }
        $catalog = $this->grcCatalogItems();
        if ([] === $catalog) {
            return $this->error('COMPLIANCE_CATALOG_EMPTY', 'Aucun résultat de conformité n’est disponible pour cette organisation.', 422);
        }
        $limit = $this->aiCopilotLimiter->create(sprintf('%d|%d', $actor->getOrganization()->getId(), $actor->getId()))->consume();
        if (!$limit->isAccepted()) {
            return $this->error('AI_RATE_LIMIT', 'Quota du copilote atteint. Réessayez plus tard.', 429);
        }
        $coverage = $this->grcCoverage($catalog);
        $gaps = array_values(array_filter($catalog, static fn (array $item): bool => in_array($item['status'], ['PARTIAL', 'NON_COMPLIANT', 'NOT_ASSESSED'], true)));
        try {
            $brief = $this->client->draftGrcBrief($settings, $objective, ['coverage' => $coverage, 'gaps' => array_slice($gaps, 0, 120)], $actor->getLocale(), hash('sha256', sprintf('riskpilot|%d|%d', $actor->getOrganization()->getId(), $actor->getId())));
        } catch (\Throwable) {
            return $this->error('AI_PROVIDER_FAILED', 'Le fournisseur IA n’a pas pu produire une synthèse GRC valide.', 502);
        }
        $actionableIds = array_column(array_slice($gaps, 0, 120), 'id');
        $seen = [];
        foreach ($brief['priorities'] as $priority) {
            if (!in_array($priority['complianceResultId'], $actionableIds, true) || isset($seen[$priority['complianceResultId']])) {
                return $this->error('AI_DRAFT_INVALID_RELATION', 'La synthèse IA référence un résultat indisponible ou dupliqué.', 502);
            }
            $seen[$priority['complianceResultId']] = true;
        }
        $request->attributes->set('_audit_after', [[
            'provider' => $settings->getProvider(), 'model' => $settings->getModel(), 'dataPolicy' => $settings->getDataPolicy(),
            'requestHash' => hash('sha256', $objective), 'workflow' => 'GRC_BRIEF', 'automaticWrite' => false,
        ]]);

        return new JsonResponse(['brief' => $brief, 'coverage' => $coverage, 'automaticWrite' => false, 'notice' => 'Synthèse indicative : vérifiez chaque priorité avant de préparer une action.']);
    }

    #[Route('/compliance-action-draft', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function complianceActionDraft(Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $settings = $this->findSettings();
        if (!$settings instanceof AiSettings || !$settings->isEnabled() || null === $settings->getEncryptedApiKey()) {
            return $this->error('AI_DISABLED', 'Le copilote IA n’est pas configuré et activé pour cette organisation.', 409);
        }
        if ('CUSTOM' === $settings->getProvider()) {
            return $this->error('CUSTOM_PROVIDER_UNAVAILABLE', 'Les endpoints personnalisés ne sont pas autorisés pour ce workflow.', 422);
        }
        $input = $request->toArray();
        $prompt = trim((string) ($input['prompt'] ?? ''));
        if (true !== ($input['consent'] ?? false) || mb_strlen($prompt) < 10 || mb_strlen($prompt) > 2000) {
            return $this->error('INVALID_REQUEST', 'Le consentement et une demande de 10 à 2 000 caractères sont obligatoires.', 422);
        }
        $catalog = $this->complianceCatalogItems();
        if ([] === $catalog) {
            return $this->error('COMPLIANCE_CATALOG_EMPTY', 'Aucune exigence partielle, non conforme ou non évaluée n’est disponible.', 422);
        }
        $limit = $this->aiCopilotLimiter->create(sprintf('%d|%d', $actor->getOrganization()->getId(), $actor->getId()))->consume();
        if (!$limit->isAccepted()) {
            return $this->error('AI_RATE_LIMIT', 'Quota du copilote atteint. Réessayez plus tard.', 429);
        }
        try {
            $draft = $this->client->draftComplianceAction($settings, $prompt, $catalog, $actor->getLocale(), hash('sha256', sprintf('riskpilot|%d|%d', $actor->getOrganization()->getId(), $actor->getId())));
        } catch (\Throwable) {
            return $this->error('AI_PROVIDER_FAILED', 'Le fournisseur IA n’a pas pu produire un brouillon d’action conformité valide.', 502);
        }
        if (!in_array($draft['complianceResultId'], array_column($catalog, 'id'), true)) {
            return $this->error('AI_DRAFT_INVALID_RELATION', 'Le fournisseur IA a proposé une exigence qui ne fait pas partie de votre organisation.', 502);
        }
        $request->attributes->set('_audit_after', [[
            'provider' => $settings->getProvider(), 'model' => $settings->getModel(), 'dataPolicy' => $settings->getDataPolicy(),
            'requestHash' => hash('sha256', $prompt), 'workflow' => 'COMPLIANCE_ACTION_DRAFT', 'automaticWrite' => false,
        ]]);

        return new JsonResponse(['draft' => $draft, 'automaticWrite' => false, 'notice' => 'Brouillon généré : relisez, choisissez un responsable et confirmez avant création.']);
    }

    #[Route('/risk-draft', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function riskDraft(Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $settings = $this->findSettings();
        if (!$settings instanceof AiSettings || !$settings->isEnabled() || null === $settings->getEncryptedApiKey()) {
            return $this->error('AI_DISABLED', 'Le copilote IA n’est pas configuré et activé pour cette organisation.', 409);
        }
        if ('CUSTOM' === $settings->getProvider()) {
            return $this->error('CUSTOM_PROVIDER_UNAVAILABLE', 'Les endpoints personnalisés ne sont pas autorisés pour ce workflow.', 422);
        }
        $input = $request->toArray();
        $prompt = trim((string) ($input['prompt'] ?? ''));
        if (true !== ($input['consent'] ?? false) || mb_strlen($prompt) < 10 || mb_strlen($prompt) > 2000) {
            return $this->error('INVALID_REQUEST', 'Le consentement et une demande de 10 à 2 000 caractères sont obligatoires.', 422);
        }
        $catalog = [
            'scopes' => $this->catalog($this->scopes->findVisibleTo($actor)),
            'assets' => $this->catalog($this->assets->findVisibleTo($actor)),
            'threats' => $this->catalog($this->threats->findVisibleTo($actor)),
        ];
        if ([] === $catalog['scopes'] || [] === $catalog['assets'] || [] === $catalog['threats']) {
            return $this->error('RISK_CATALOG_INCOMPLETE', 'Créez au moins un périmètre, un actif et une menace avant de générer un risque.', 422);
        }
        $limit = $this->aiCopilotLimiter->create(sprintf('%d|%d', $actor->getOrganization()->getId(), $actor->getId()))->consume();
        if (!$limit->isAccepted()) {
            return $this->error('AI_RATE_LIMIT', 'Quota du copilote atteint. Réessayez plus tard.', 429);
        }
        try {
            $draft = $this->client->draftRisk($settings, $prompt, $catalog, $actor->getLocale(), hash('sha256', sprintf('riskpilot|%d|%d', $actor->getOrganization()->getId(), $actor->getId())));
        } catch (\Throwable) {
            return $this->error('AI_PROVIDER_FAILED', 'Le fournisseur IA n’a pas pu produire un brouillon de risque valide.', 502);
        }
        if (!$this->catalogContains($catalog['scopes'], $draft['scopeId']) || !$this->catalogContains($catalog['assets'], $draft['assetId']) || !$this->catalogContains($catalog['threats'], $draft['threatId'])) {
            return $this->error('AI_DRAFT_INVALID_RELATION', 'Le fournisseur IA a proposé une relation qui ne fait pas partie de votre organisation.', 502);
        }
        $request->attributes->set('_audit_after', [[
            'provider' => $settings->getProvider(), 'model' => $settings->getModel(), 'dataPolicy' => $settings->getDataPolicy(),
            'requestHash' => hash('sha256', $prompt), 'workflow' => 'RISK_DRAFT', 'automaticWrite' => false,
        ]]);

        return new JsonResponse(['draft' => $draft, 'automaticWrite' => false, 'notice' => 'Brouillon généré : relisez et confirmez avant création.']);
    }

    #[Route('/context', methods: ['GET'])]
    public function context(): JsonResponse
    {
        $settings = $this->findSettings();

        return new JsonResponse([
            'enabled' => $settings?->isEnabled() && null !== $settings->getEncryptedApiKey(),
            'provider' => $settings?->getProvider(),
            'model' => $settings?->getModel(),
            'dataPolicy' => $settings?->getDataPolicy() ?? 'MINIMAL',
            'modes' => ['ASSIST', 'PILOT'],
            'capabilities' => ['GUIDANCE', 'NAVIGATION', 'GRC_BRIEF', 'RISK_DRAFT', 'COMPLIANCE_ACTION_DRAFT', 'ISMS_DOCUMENT_DRAFT'],
            'automaticWrite' => false,
            'notice' => 'Toute création passe par un brouillon relu et une confirmation humaine explicite.',
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function ask(Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $settings = $this->findSettings();
        if (!$settings instanceof AiSettings || !$settings->isEnabled() || null === $settings->getEncryptedApiKey()) {
            return $this->error('AI_DISABLED', 'Le copilote IA n’est pas configuré et activé pour cette organisation.', 409);
        }
        if ('CUSTOM' === $settings->getProvider()) {
            return $this->error('CUSTOM_PROVIDER_UNAVAILABLE', 'Les endpoints personnalisés ne sont pas autorisés pour le chatbot tant que leur sécurité réseau n’est pas validée.', 422);
        }
        $input = $request->toArray();
        $question = trim((string) ($input['question'] ?? ''));
        $mode = strtoupper(trim((string) ($input['mode'] ?? 'ASSIST')));
        $currentPath = trim((string) ($input['currentPath'] ?? '/'));
        if (!in_array($mode, ['ASSIST', 'PILOT'], true) || mb_strlen($currentPath) > 200) {
            return $this->error('INVALID_MODE', 'Le mode ou le contexte de navigation est invalide.', 422);
        }
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
        try {
            $safetyIdentifier = hash('sha256', sprintf('riskpilot|%d|%d', $actor->getOrganization()->getId(), $actor->getId()));
            $result = 'PILOT' === $mode
                ? $this->client->pilot($settings, $question, $history, $actor->getLocale(), $safetyIdentifier, $currentPath, $this->pilotCapabilities())
                : ['answer' => $this->client->askGlobal($settings, $question, $history, $actor->getLocale(), $safetyIdentifier), 'actions' => []];
        } catch (\Throwable) {
            return $this->error('AI_PROVIDER_FAILED', 'Le fournisseur IA n’a pas pu produire de réponse.', 502);
        }
        $request->attributes->set('_audit_after', [[
            'provider' => $settings->getProvider(),
            'model' => $settings->getModel(),
            'dataPolicy' => $settings->getDataPolicy(),
            'questionHash' => hash('sha256', $question),
            'workflow' => 'GLOBAL_COPILOT',
            'mode' => $mode,
            'automaticWrite' => false,
        ]]);

        return new JsonResponse([
            'answer' => $result['answer'],
            'actions' => $this->validatedPilotActions($result['actions']),
            'mode' => $mode,
            'provider' => $settings->getProvider(),
            'model' => $settings->getModel(),
            'automaticWrite' => false,
            'notice' => 'Conseil à vérifier humainement. Utilisez un brouillon guidé pour créer un objet.',
        ]);
    }

    private function findSettings(): ?AiSettings
    {
        return $this->settings->findOneBy(['organization' => $this->currentUser->get()->getOrganization()]);
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

    /** @return list<array{type: string, label: string, path?: string}> */
    private function pilotCapabilities(): array
    {
        $english = 'en' === $this->currentUser->get()->getLocale();
        $routes = [
            '/' => ['Tableau de bord', 'Dashboard'], '/risks' => ['Registre des risques', 'Risk register'],
            '/risk-matrix' => ['Matrice des risques', 'Risk matrix'], '/actions' => ['Plans d’action', 'Action plans'],
            '/operations' => ['Pilotage opérationnel', 'Operations'], '/search' => ['Recherche transverse', 'Global search'],
            '/decision' => ['Espace de décision', 'Decision workspace'], '/experiments' => ['Propositions gouvernées', 'Governed proposals'],
            '/ebios' => ['EBIOS RM', 'EBIOS RM'], '/indicators' => ['Indicateurs', 'Indicators'],
            '/annual-reports' => ['Rapports annuels', 'Annual reports'], '/compliance' => ['Conformité', 'Compliance'],
            '/nis2' => ['Conformité NIS2', 'NIS2 compliance'], '/third-parties' => ['Tiers et fournisseurs', 'Third parties'],
            '/resilience' => ['Incidents et continuité', 'Resilience and continuity'],
            '/regulatory' => ['Vie privée et obligations', 'Privacy and regulatory records'],
            '/isms-documents' => ['Documents ISMS', 'ISMS documents'], '/notifications' => ['Notifications', 'Notifications'],
            '/profile' => ['Profil utilisateur', 'User profile'],
        ];
        $actions = [];
        foreach ($routes as $path => [$frenchLabel, $englishLabel]) {
            $actions[] = ['type' => 'NAVIGATE', 'label' => $english ? $englishLabel : $frenchLabel, 'path' => $path];
        }
        if ([] !== array_intersect([User::ROLE_RISK_MANAGER, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], $this->currentUser->get()->getRoles())) {
            $actions[] = ['type' => 'OPEN_RISK_DRAFT', 'label' => $english ? 'Prepare a risk draft' : 'Préparer un brouillon de risque'];
            $actions[] = ['type' => 'OPEN_COMPLIANCE_ACTION_DRAFT', 'label' => $english ? 'Prepare a compliance action draft' : 'Préparer un brouillon d’action conformité'];
        }
        $actions[] = ['type' => 'OPEN_ISMS_DOCUMENT_DRAFT', 'label' => $english ? 'Prepare an ISMS document draft' : 'Préparer un brouillon de document ISMS'];

        return $actions;
    }

    /** @param list<array{type: string, label: string, path?: string}> $actions
     * @return list<array{type: string, label: string, path?: string}>
     */
    private function validatedPilotActions(array $actions): array
    {
        $allowed = $this->pilotCapabilities();

        $validated = [];
        foreach ($actions as $action) {
            foreach ($allowed as $capability) {
                if ($action['type'] === $capability['type'] && ('NAVIGATE' !== $action['type'] || ($action['path'] ?? null) === ($capability['path'] ?? null))) {
                    $validated[] = $capability;
                    break;
                }
            }
        }

        return $validated;
    }

    /** @param list<object> $entities
     * @return list<array{id: int, name: string}>
     */
    private function catalog(array $entities): array
    {
        return array_map(static fn (object $entity): array => ['id' => (int) $entity->getId(), 'name' => mb_substr($entity->getName(), 0, 180)], array_slice($entities, 0, 200));
    }

    /** @param list<array{id: int, name: string}> $catalog */
    private function catalogContains(array $catalog, int $id): bool
    {
        return in_array($id, array_column($catalog, 'id'), true);
    }

    /** @return list<array{id: int, label: string, status: string, requirementId: int, frameworkId: int}> */
    private function complianceCatalogItems(): array
    {
        return array_map(static function (\App\Entity\ComplianceResult $result): array {
            $requirement = $result->getRequirement();
            $framework = $requirement->getFramework();

            return [
                'id' => (int) $result->getId(),
                'label' => mb_substr(sprintf('%s %s · %s — %s', $framework->getName(), $framework->getVersion(), $requirement->getReference(), $requirement->getTitle()), 0, 400),
                'status' => $result->getComplianceStatus(),
                'requirementId' => (int) $requirement->getId(),
                'frameworkId' => (int) $framework->getId(),
            ];
        }, $this->complianceResults->findActionableVisibleTo($this->currentUser->get()));
    }

    /** @return list<array{id: int, framework: string, reference: string, title: string, status: string}> */
    private function grcCatalogItems(): array
    {
        return array_map(static function (\App\Entity\ComplianceResult $result): array {
            $requirement = $result->getRequirement();
            $framework = $requirement->getFramework();

            return [
                'id' => (int) $result->getId(),
                'framework' => mb_substr(sprintf('%s %s', $framework->getName(), $framework->getVersion()), 0, 180),
                'reference' => mb_substr($requirement->getReference(), 0, 80),
                'title' => mb_substr($requirement->getTitle(), 0, 300),
                'status' => $result->getComplianceStatus(),
            ];
        }, $this->complianceResults->findVisibleTo($this->currentUser->get()));
    }

    /**
     * @param list<array{id: int, framework: string, reference: string, title: string, status: string}> $catalog
     *
     * @return list<array{framework: string, total: int, compliant: int, partial: int, nonCompliant: int, notAssessed: int, notApplicable: int}>
     */
    private function grcCoverage(array $catalog): array
    {
        $coverage = [];
        foreach ($catalog as $item) {
            $framework = $item['framework'];
            $coverage[$framework] ??= ['framework' => $framework, 'total' => 0, 'compliant' => 0, 'partial' => 0, 'nonCompliant' => 0, 'notAssessed' => 0, 'notApplicable' => 0];
            ++$coverage[$framework]['total'];
            $key = match ($item['status']) {
                'COMPLIANT' => 'compliant', 'PARTIAL' => 'partial', 'NON_COMPLIANT' => 'nonCompliant',
                'NOT_ASSESSED' => 'notAssessed', default => 'notApplicable',
            };
            ++$coverage[$framework][$key];
        }

        return array_values($coverage);
    }
}
