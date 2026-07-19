<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Domain\Risk\RiskCalculation;
use App\Entity\ActionPlan;
use App\Entity\ComplianceAssessment;
use App\Entity\RiskScenario;
use App\Repository\ActionPlanRepository;
use App\Repository\ComplianceAssessmentRepository;
use App\Repository\RiskScenarioRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DashboardController
{
    public function __construct(private CurrentUser $currentUser, private RiskScenarioRepository $risks, private ActionPlanRepository $actions, private ComplianceAssessmentRepository $assessments, private RiskCalculation $calculation)
    {
    }

    #[Route('/api/dashboard', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $actor = $this->currentUser->get();
        $risks = $this->risks->findVisibleTo($actor);
        $actions = $this->actions->findVisibleTo($actor);
        $assessments = $this->assessments->findVisibleTo($actor);
        $thresholds = $actor->getOrganization()->getRiskThresholds();
        $riskLevels = ['LOW' => 0, 'MODERATE' => 0, 'HIGH' => 0, 'CRITICAL' => 0];
        $actionStatuses = [];
        foreach ($risks as $risk) {
            ++$riskLevels[$this->calculation->level($risk->getCurrentRiskScore(), $thresholds)->value];
        }
        foreach ($actions as $action) {
            $actionStatuses[$action->getStatus()] = ($actionStatuses[$action->getStatus()] ?? 0) + 1;
        }
        $dueLimit = new \DateTimeImmutable('+30 days');
        $dueActions = array_values(array_filter($actions, fn (ActionPlan $action): bool => !in_array($action->getStatus(), ['COMPLETED', 'CANCELLED'], true) && $action->getDueDate() <= $dueLimit));
        $scores = array_map(fn (ComplianceAssessment $item): float => $item->getGlobalScore(), array_filter($assessments, fn (ComplianceAssessment $item): bool => 'COMPLETED' === $item->getStatus()));
        $complianceByFramework = [];
        foreach ($assessments as $assessment) {
            $key = $assessment->getFramework()->getName().' '.$assessment->getFramework()->getVersion();
            $complianceByFramework[$key] = max($complianceByFramework[$key] ?? 0, $assessment->getGlobalScore());
        }

        return new JsonResponse([
            'summary' => ['totalRisks' => count($risks), 'criticalRisks' => $riskLevels['CRITICAL'], 'highRisks' => $riskLevels['HIGH'], 'overdueActions' => $actionStatuses['OVERDUE'] ?? 0, 'dueActions' => count($dueActions), 'globalCompliance' => [] === $scores ? 0 : round(array_sum($scores) / count($scores), 1)],
            'riskLevels' => $riskLevels, 'actionStatuses' => $actionStatuses, 'complianceByFramework' => $complianceByFramework,
            'topRisks' => array_map(fn (RiskScenario $risk): array => ['id' => $risk->getId(), 'title' => $risk->getTitle(), 'score' => $risk->getCurrentRiskScore(), 'status' => $risk->getStatus()], array_slice($risks, 0, 10)),
            'dueActions' => array_map(fn (ActionPlan $action): array => ['id' => $action->getId(), 'title' => $action->getTitle(), 'dueDate' => $action->getDueDate()->format('Y-m-d'), 'status' => $action->getStatus(), 'priority' => $action->getPriority()], array_slice($dueActions, 0, 10)),
        ]);
    }
}
