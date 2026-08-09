<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Application\PdfReportRenderer;
use App\Entity\ActionPlan;
use App\Entity\Asset;
use App\Entity\ComplianceAssessment;
use App\Entity\ExecutiveGovernanceRecord;
use App\Entity\OperationalRecord;
use App\Entity\PlatformIntegration;
use App\Entity\RiskScenario;
use App\Entity\SupplierAssessment;
use App\Entity\ThirdParty;
use App\Entity\User;
use App\Repository\ActionPlanRepository;
use App\Repository\ComplianceAssessmentRepository;
use App\Repository\ExecutiveGovernanceRecordRepository;
use App\Repository\OperationalRecordRepository;
use App\Repository\OrganizationRepository;
use App\Repository\PlatformIntegrationRepository;
use App\Repository\RiskScenarioRepository;
use App\Repository\SecurityControlRepository;
use App\Repository\ThirdPartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/decision')]
final readonly class DecisionWorkspaceController
{
    public function __construct(
        private CurrentUser $currentUser,
        private OperationalRecordRepository $records,
        private ExecutiveGovernanceRecordRepository $governance,
        private PlatformIntegrationRepository $integrations,
        private ThirdPartyRepository $thirdParties,
        private RiskScenarioRepository $risks,
        private SecurityControlRepository $controls,
        private ComplianceAssessmentRepository $assessments,
        private ActionPlanRepository $actions,
        private OrganizationRepository $organizations,
        private EntityManagerInterface $entityManager,
        private PdfReportRenderer $pdf,
    ) {
    }

    #[Route('/projects/{id<\d+>}/transition', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function transitionProject(int $id, Request $request): JsonResponse
    {
        $project = $this->record($id, 'SECURITY_PROJECT');
        if (null === $project) {
            return $this->error('NOT_FOUND', 'Projet introuvable.', 404);
        }
        $data = $request->toArray();
        $next = strtoupper((string) ($data['status'] ?? ''));
        $transitions = [
            'DRAFT' => ['ACTIVE'],
            'ACTIVE' => ['IN_PROGRESS', 'ARCHIVED'],
            'IN_PROGRESS' => ['AT_RISK', 'COMPLETED', 'ARCHIVED'],
            'AT_RISK' => ['IN_PROGRESS', 'COMPLETED', 'ARCHIVED'],
            'COMPLETED' => ['ARCHIVED'],
            'ARCHIVED' => [],
        ];
        if (!in_array($next, $transitions[$project->getStatus()] ?? [], true)) {
            return $this->error('INVALID_TRANSITION', 'Transition de projet interdite.', 422);
        }
        $details = $project->getDetails();
        try {
            $this->validateProjectLinks($details);
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_PROJECT', $e->getMessage(), 422);
        }
        if ('COMPLETED' === $next && (empty($details['securityOpinion']) || empty($details['productionDecision']) || empty($details['milestones']))) {
            return $this->error('GATES_INCOMPLETE', 'Avis sécurité, jalons et décision de mise en production sont requis.', 422);
        }
        $history = (array) ($details['history'] ?? []);
        $history[] = ['from' => $project->getStatus(), 'to' => $next, 'at' => (new \DateTimeImmutable())->format(DATE_ATOM), 'actorId' => $this->currentUser->get()->getId(), 'comment' => trim((string) ($data['comment'] ?? ''))];
        $details['history'] = $history;
        $project->update($project->getTitle(), $next, $details, $project->getOwner(), $project->getDueAt());
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($project));
    }

    #[Route('/financial-scenarios/{id<\d+>}/simulate', methods: ['POST'])]
    public function simulate(int $id, Request $request): JsonResponse
    {
        $scenario = $this->governance->find($id);
        $actor = $this->currentUser->get();
        if (!$scenario instanceof ExecutiveGovernanceRecord || $scenario->getOrganization() !== $actor->getOrganization() || 'FINANCIAL_SCENARIO' !== $scenario->getType()) {
            return $this->error('NOT_FOUND', 'Scénario financier introuvable.', 404);
        }
        $details = [...$scenario->getDetails(), ...$request->toArray()];
        if ('APPROVED' !== $scenario->getStatus() || true !== ($details['financeApproval']['approved'] ?? false)) {
            return $this->error('FINANCE_APPROVAL_REQUIRED', 'Le modèle, ses unités et ses hypothèses doivent être approuvés par la finance.', 422);
        }
        try {
            $result = $this->quantify($details);
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_MODEL', $e->getMessage(), 422);
        }

        return new JsonResponse(['scenarioId' => $scenario->getId(), 'modelVersion' => (string) ($details['modelVersion'] ?? '1'), 'currency' => (string) ($details['currency'] ?? 'EUR'), 'samples' => 1000, ...$result]);
    }

    #[Route('/views/{id<\d+>}/snapshot', methods: ['GET'])]
    public function viewSnapshot(int $id): JsonResponse
    {
        $view = $this->record($id, 'SAVED_VIEW');
        if (null === $view) {
            return $this->error('NOT_FOUND', 'Vue introuvable.', 404);
        }
        $details = $view->getDetails();
        if ($view->getOwner() !== $this->currentUser->get() && true !== ($details['shared'] ?? false)) {
            return $this->error('NOT_FOUND', 'Vue introuvable.', 404);
        }

        return new JsonResponse(['view' => $this->serialize($view), 'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'data' => $this->tenantSnapshot()]);
    }

    #[Route('/platform-vision', methods: ['GET'])]
    #[IsGranted(User::ROLE_SUPER_ADMIN)]
    public function platformVision(): JsonResponse
    {
        $items = [];
        foreach ($this->organizations->findVisibleTo($this->currentUser->get()) as $organization) {
            $items[] = [
                'organization' => ['id' => $organization->getId(), 'name' => $organization->getName()],
                'risks' => count($this->entityManager->getRepository(RiskScenario::class)->findBy(['organization' => $organization])),
                'actions' => count($this->entityManager->getRepository(ActionPlan::class)->findBy(['organization' => $organization])),
                'thirdParties' => count($this->entityManager->getRepository(ThirdParty::class)->findBy(['organization' => $organization])),
            ];
        }

        return new JsonResponse(['generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'organizations' => $items]);
    }

    #[Route('/reports/{id<\d+>}/run', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function runReport(int $id): JsonResponse
    {
        $template = $this->record($id, 'REPORT_TEMPLATE');
        if (null === $template || 'ACTIVE' !== $template->getStatus() || true !== ($template->getDetails()['approved'] ?? false)) {
            return $this->error('NOT_FOUND', 'Modèle de rapport actif introuvable.', 404);
        }
        $actor = $this->currentUser->get();
        $generatedAt = new \DateTimeImmutable();
        $details = ['templateId' => $template->getId(), 'templateVersion' => (string) ($template->getDetails()['version'] ?? '1'), 'reportType' => (string) ($template->getDetails()['reportType'] ?? 'MANAGEMENT_COMMITTEE'), 'approvedBy' => (string) ($template->getDetails()['approvedBy'] ?? ''), 'organization' => $actor->getOrganization()->getName(), 'generatedAt' => $generatedAt->format(DATE_ATOM), 'generatedBy' => trim($actor->getFirstName().' '.$actor->getLastName()), 'blocks' => $template->getDetails()['blocks'] ?? [], 'snapshot' => $this->tenantSnapshot()];
        $run = new OperationalRecord($actor->getOrganization(), 'REPORT_RUN', $template->getTitle().' — '.$generatedAt->format('Y-m-d H:i'), $details);
        $run->update($run->getTitle(), 'COMPLETED', $details, $actor, null);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($run), 201);
    }

    #[Route('/reports/{id<\d+>}/export', methods: ['GET'])]
    public function exportReport(int $id, Request $request): Response
    {
        $run = $this->record($id, 'REPORT_RUN');
        if (null === $run) {
            return $this->error('NOT_FOUND', 'Rapport introuvable.', 404);
        }
        $format = strtolower((string) $request->query->get('format', 'pdf'));
        $details = $run->getDetails();
        $details['organization'] ??= $run->getOrganization()->getName();
        $details['generatedBy'] ??= null === $run->getOwner() ? 'RiskPilot' : trim($run->getOwner()->getFirstName().' '.$run->getOwner()->getLastName());
        if ('pdf' === $format) {
            return new Response($this->pdf->renderDecisionReport($run->getTitle(), $details), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => sprintf('attachment; filename="riskpilot-report-%d.pdf"', $id), 'X-Content-Type-Options' => 'nosniff']);
        }
        if ('json' !== $format) {
            return $this->error('UNSUPPORTED_FORMAT', 'Formats disponibles : PDF ou JSON.', 400);
        }

        return new JsonResponse([...$this->serialize($run), 'details' => $details], 200, ['Content-Disposition' => sprintf('attachment; filename="riskpilot-report-%d.json"', $id)]);
    }

    #[Route('/connectors/{id<\d+>}/reconcile', methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function reconcile(int $id, Request $request): JsonResponse
    {
        $integration = $this->integrations->find($id);
        $actor = $this->currentUser->get();
        if (!$integration instanceof PlatformIntegration || $integration->getOrganization() !== $actor->getOrganization() || 'CONNECTOR' !== $integration->getType() || !$integration->isEnabled()) {
            return $this->error('NOT_FOUND', 'Connecteur actif introuvable.', 404);
        }
        $data = $request->toArray();
        $details = ['integrationId' => $integration->getId(), 'provider' => $integration->getProvider(), 'direction' => $integration->getConfiguration()['direction'] ?? 'IMPORT', 'dryRun' => (bool) ($data['dryRun'] ?? true), 'idempotencyKey' => (string) ($data['idempotencyKey'] ?? ''), 'received' => count((array) ($data['items'] ?? [])), 'conflicts' => [], 'startedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)];
        if ('' === $details['idempotencyKey']) {
            return $this->error('IDEMPOTENCY_REQUIRED', 'Une clé d’idempotence est obligatoire.', 422);
        }
        foreach ($this->records->findForOrganization($actor->getOrganization(), 'CONNECTOR_SYNC') as $previous) {
            if (($previous->getDetails()['idempotencyKey'] ?? null) === $details['idempotencyKey']) {
                return new JsonResponse($this->serialize($previous));
            }
        }
        $sync = new OperationalRecord($actor->getOrganization(), 'CONNECTOR_SYNC', $integration->getName().' — rapprochement', $details);
        $sync->update($sync->getTitle(), 'COMPLETED', $details, $actor, null);
        $this->entityManager->persist($sync);
        $integration->markUsed();
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($sync), 201);
    }

    #[Route('/tprm/portfolio', methods: ['GET'])]
    public function tprmPortfolio(): JsonResponse
    {
        $items = [];
        $scores = [];
        foreach ($this->thirdParties->findVisibleTo($this->currentUser->get()) as $thirdParty) {
            $assessments = $thirdParty->getAssessments()->toArray();
            usort($assessments, static fn (SupplierAssessment $left, SupplierAssessment $right): int => $right->getExpiresAt() <=> $left->getExpiresAt());
            $latest = [] === $assessments ? null : end($assessments);
            $scores[] = $thirdParty->getCyberScore();
            $items[] = [
                'id' => $thirdParty->getId(),
                'name' => $thirdParty->getName(),
                'segment' => match ($thirdParty->getCriticality()) {
                    'CRITICAL' => 'DEEP', 'HIGH' => 'STANDARD', default => 'LIGHT',
                },
                'criticality' => $thirdParty->getCriticality(),
                'cyberScore' => $thirdParty->getCyberScore(),
                'latestAssessment' => $latest instanceof SupplierAssessment ? ['status' => $latest->getStatus(), 'score' => $latest->getScore(), 'expiresAt' => $latest->getExpiresAt()->format(DATE_ATOM)] : null,
                'nextAssessmentAt' => $thirdParty->getNextAssessmentAt()?->format(DATE_ATOM),
                'contractEndsAt' => $thirdParty->getContractEndsAt()?->format(DATE_ATOM),
                'dependency' => $thirdParty->getDependencies(),
                'exitPlan' => $thirdParty->getExitPlan(),
                'alerts' => $this->thirdPartyAlerts($thirdParty),
            ];
        }

        return new JsonResponse(['generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'summary' => ['total' => count($items), 'averageCyberScore' => [] === $scores ? 0 : round(array_sum($scores) / count($scores), 2), 'critical' => count(array_filter($items, static fn (array $item): bool => 'CRITICAL' === $item['criticality']))], 'items' => $items]);
    }

    /** @param array<string, mixed> $details */
    private function validateProjectLinks(array $details): void
    {
        $organization = $this->currentUser->get()->getOrganization();
        foreach (['assetIds' => Asset::class, 'riskIds' => RiskScenario::class, 'actionIds' => ActionPlan::class] as $field => $class) {
            foreach ((array) ($details[$field] ?? []) as $id) {
                if (null === $this->entityManager->getRepository($class)->findOneBy(['id' => (int) $id, 'organization' => $organization])) {
                    throw new \InvalidArgumentException(sprintf('%s contient une référence étrangère ou inexistante.', $field));
                }
            }
        }
    }

    /** @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function quantify(array $details): array
    {
        $frequencyMin = (float) ($details['frequencyMin'] ?? -1);
        $frequencyMax = (float) ($details['frequencyMax'] ?? -1);
        $lossMin = (float) ($details['lossMin'] ?? -1);
        $lossMode = (float) ($details['lossMostLikely'] ?? -1);
        $lossMax = (float) ($details['lossMax'] ?? -1);
        if ($frequencyMin < 0 || $frequencyMax < $frequencyMin || $lossMin < 0 || $lossMin > $lossMode || $lossMode > $lossMax) {
            throw new \InvalidArgumentException('Fréquences et pertes doivent former des fourchettes positives ordonnées.');
        }
        $indirectFactor = max(0.0, (float) ($details['indirectLossFactor'] ?? 0));
        $samples = [];
        for ($i = 1; $i <= 1000; ++$i) {
            $u = fmod($i * 0.61803398875, 1.0);
            $v = fmod($i * 0.41421356237, 1.0);
            $frequency = $frequencyMin + ($frequencyMax - $frequencyMin) * $u;
            $pivot = ($lossMode - $lossMin) / max(0.000001, $lossMax - $lossMin);
            $loss = $v < $pivot ? $lossMin + sqrt($v * ($lossMax - $lossMin) * ($lossMode - $lossMin)) : $lossMax - sqrt((1 - $v) * ($lossMax - $lossMin) * ($lossMax - $lossMode));
            $samples[] = $frequency * $loss * (1 + $indirectFactor);
        }
        sort($samples);
        $mean = array_sum($samples) / count($samples);

        return ['annualLoss' => ['mean' => round($mean, 2), 'p50' => round($samples[499], 2), 'p90' => round($samples[899], 2), 'p95' => round($samples[949], 2)], 'confidenceInterval90' => ['low' => round($samples[49], 2), 'high' => round($samples[949], 2)], 'sensitivity' => ['frequency' => round(($frequencyMax - $frequencyMin) * $lossMode, 2), 'loss' => round(($lossMax - $lossMin) * (($frequencyMin + $frequencyMax) / 2), 2), 'indirectLossFactor' => $indirectFactor]];
    }

    /** @return array<string, mixed> */
    private function tenantSnapshot(): array
    {
        $actor = $this->currentUser->get();
        $risks = $this->risks->findVisibleTo($actor);
        $actions = $this->actions->findVisibleTo($actor);
        $assessments = $this->assessments->findVisibleTo($actor);
        usort($risks, static fn (RiskScenario $left, RiskScenario $right): int => $right->getCurrentRiskScore() <=> $left->getCurrentRiskScore());
        usort($actions, static fn (ActionPlan $left, ActionPlan $right): int => $left->getDueDate() <=> $right->getDueDate());

        return [
            'risks' => count($risks),
            'controls' => count($this->controls->findVisibleTo($actor)),
            'assessments' => count($assessments),
            'actions' => count($actions),
            'thirdParties' => count($this->thirdParties->findVisibleTo($actor)),
            'riskItems' => array_map(static fn (RiskScenario $risk): array => ['title' => $risk->getTitle(), 'status' => $risk->getStatus(), 'currentScore' => $risk->getCurrentRiskScore(), 'residualScore' => $risk->getResidualRiskScore(), 'treatment' => $risk->getTreatmentDecision(), 'owner' => trim($risk->getRiskOwner()->getFirstName().' '.$risk->getRiskOwner()->getLastName())], array_slice($risks, 0, 10)),
            'actionItems' => array_map(static fn (ActionPlan $action): array => ['title' => $action->getTitle(), 'status' => $action->getStatus(), 'priority' => $action->getPriority(), 'progress' => $action->getProgress(), 'dueAt' => $action->getDueDate()->format('Y-m-d'), 'owner' => trim($action->getOwner()->getFirstName().' '.$action->getOwner()->getLastName())], array_slice($actions, 0, 10)),
            'complianceItems' => array_map(static fn (ComplianceAssessment $assessment): array => ['framework' => $assessment->getFramework()->getName().' '.$assessment->getFramework()->getVersion(), 'scope' => $assessment->getScope()->getName(), 'status' => $assessment->getStatus(), 'score' => $assessment->getGlobalScore(), 'assessedAt' => $assessment->getAssessmentDate()->format('Y-m-d')], array_slice($assessments, 0, 10)),
        ];
    }

    /** @return list<string> */
    private function thirdPartyAlerts(ThirdParty $thirdParty): array
    {
        $alerts = [];
        $today = new \DateTimeImmutable('today');
        if (null !== $thirdParty->getNextAssessmentAt() && $thirdParty->getNextAssessmentAt() < $today) {
            $alerts[] = 'ASSESSMENT_OVERDUE';
        }
        if (null !== $thirdParty->getContractEndsAt() && $thirdParty->getContractEndsAt() < $today->modify('+90 days')) {
            $alerts[] = 'CONTRACT_EXPIRING';
        }
        if ('CRITICAL' === $thirdParty->getCriticality() && null === $thirdParty->getExitPlan()) {
            $alerts[] = 'EXIT_PLAN_MISSING';
        }

        return $alerts;
    }

    private function record(int $id, string $type): ?OperationalRecord
    {
        $record = $this->records->findOneVisible($id, $this->currentUser->get()->getOrganization());

        return null !== $record && $type === $record->getType() ? $record : null;
    }

    /** @return array<string, mixed> */
    private function serialize(OperationalRecord $record): array
    {
        return ['id' => $record->getId(), 'type' => $record->getType(), 'title' => $record->getTitle(), 'status' => $record->getStatus(), 'details' => $record->getDetails(), 'ownerId' => $record->getOwner()?->getId(), 'dueAt' => $record->getDueAt()?->format(DATE_ATOM), 'updatedAt' => $record->getUpdatedAt()->format(DATE_ATOM)];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'message' => $message], $status);
    }
}
