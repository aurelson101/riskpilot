<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Application\PdfReportRenderer;
use App\Domain\Risk\RiskCalculation;
use App\Entity\ActionPlan;
use App\Entity\ExecutiveGovernanceRecord;
use App\Entity\RiskScenario;
use App\Repository\ActionPlanRepository;
use App\Repository\ComplianceAssessmentRepository;
use App\Repository\ExecutiveGovernanceRecordRepository;
use App\Repository\RiskScenarioRepository;
use App\Repository\SecurityControlRepository;
use App\Repository\ThirdPartyRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DashboardController
{
    public function __construct(private CurrentUser $currentUser, private RiskScenarioRepository $risks, private ActionPlanRepository $actions, private ComplianceAssessmentRepository $assessments, private SecurityControlRepository $controls, private ThirdPartyRepository $thirdParties, private ExecutiveGovernanceRecordRepository $governanceRecords, private RiskCalculation $calculation, private PdfReportRenderer $pdf)
    {
    }

    #[Route('/api/dashboard', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->snapshot());
    }

    #[Route('/api/dashboard/export', methods: ['GET'])]
    public function export(): Response
    {
        $actor = $this->currentUser->get();
        $data = [...$this->snapshot(), 'organization' => $actor->getOrganization()->getName(), 'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'generatedBy' => trim($actor->getFirstName().' '.$actor->getLastName()), 'locale' => $actor->getLocale(), 'vision' => $this->vision()];
        $title = 'en' === $actor->getLocale() ? 'Executive report' : 'Rapport exécutif';
        $content = $this->pdf->renderExecutiveReport($title, $data, $actor->getLocale());
        $hash = hash('sha256', $content);

        return new Response($content, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="riskpilot-executive-report.pdf"', 'Content-Length' => (string) strlen($content), 'Content-Language' => $actor->getLocale(), 'Digest' => 'sha-256='.base64_encode(hex2bin($hash)), 'ETag' => '"'.$hash.'"', 'X-Content-Type-Options' => 'nosniff', 'X-RiskPilot-Document-SHA256' => $hash]);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $actor = $this->currentUser->get();
        $risks = $this->risks->findVisibleTo($actor);
        $actions = $this->actions->findVisibleTo($actor);
        $assessments = $this->assessments->findVisibleTo($actor);
        $thresholds = $actor->getOrganization()->getRiskThresholds();
        $riskLevels = ['LOW' => 0, 'MODERATE' => 0, 'HIGH' => 0, 'CRITICAL' => 0];
        $actionStatuses = [];
        $today = new \DateTimeImmutable('today');
        $dueLimit = $today->modify('+30 days');
        $dueActions = [];
        foreach ($risks as $risk) {
            ++$riskLevels[$this->calculation->level($risk->getCurrentRiskScore(), $thresholds)->value];
        }
        foreach ($actions as $action) {
            $status = $action->getStatus();
            $actionStatuses[$status] = ($actionStatuses[$status] ?? 0) + 1;
            if (!in_array($status, ['COMPLETED', 'CANCELLED'], true) && $action->getDueDate() >= $today && $action->getDueDate() <= $dueLimit) {
                $dueActions[] = $action;
            }
        }
        $scores = [];
        $complianceByFramework = [];
        foreach ($assessments as $assessment) {
            if ('COMPLETED' !== $assessment->getStatus()) {
                continue;
            }
            $scores[] = $assessment->getGlobalScore();
            $key = $assessment->getFramework()->getName().' '.$assessment->getFramework()->getVersion();
            $complianceByFramework[$key] = max($complianceByFramework[$key] ?? 0, $assessment->getGlobalScore());
        }

        return [
            'summary' => ['totalRisks' => count($risks), 'criticalRisks' => $riskLevels['CRITICAL'], 'highRisks' => $riskLevels['HIGH'], 'overdueActions' => $actionStatuses['OVERDUE'] ?? 0, 'dueActions' => count($dueActions), 'globalCompliance' => [] === $scores ? 0 : round(array_sum($scores) / count($scores), 1)],
            'riskLevels' => $riskLevels, 'actionStatuses' => $actionStatuses, 'complianceByFramework' => $complianceByFramework,
            'topRisks' => array_map(fn (RiskScenario $risk): array => ['id' => $risk->getId(), 'title' => $risk->getTitle(), 'score' => $risk->getCurrentRiskScore(), 'level' => $this->calculation->level($risk->getCurrentRiskScore(), $thresholds)->value, 'status' => $risk->getStatus()], array_slice(array_values(array_filter($risks, static fn (RiskScenario $risk): bool => !in_array($risk->getStatus(), ['CLOSED', 'ARCHIVED'], true))), 0, 10)),
            'dueActions' => array_map(fn (ActionPlan $action): array => ['id' => $action->getId(), 'title' => $action->getTitle(), 'dueDate' => $action->getDueDate()->format('Y-m-d'), 'status' => $action->getStatus(), 'priority' => $action->getPriority()], array_slice($dueActions, 0, 10)),
        ];
    }

    /** @return array<string, mixed> */
    private function vision(): array
    {
        $actor = $this->currentUser->get();
        $controls = $this->controls->findVisibleTo($actor);
        $thirdParties = $this->thirdParties->findVisibleTo($actor);
        $losses = array_values(array_filter(array_map(static fn (ExecutiveGovernanceRecord $item): ?array => 'FINANCIAL_SCENARIO' === $item->getType() ? $item->getDetails() : null, $this->governanceRecords->findVisibleTo($actor))));

        return ['controls' => ['total' => count($controls), 'implemented' => count(array_filter($controls, static fn ($item): bool => 'IMPLEMENTED' === $item->getImplementationStatus()))], 'thirdParties' => ['total' => count($thirdParties), 'critical' => count(array_filter($thirdParties, static fn ($item): bool => 'CRITICAL' === $item->getCriticality()))], 'financialScenarios' => $losses];
    }
}
