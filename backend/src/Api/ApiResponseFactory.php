<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\ActionComment;
use App\Entity\ActionPlan;
use App\Entity\Asset;
use App\Entity\ComplianceAssessment;
use App\Entity\ComplianceResult;
use App\Entity\Framework;
use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\Requirement;
use App\Entity\RiskScenario;
use App\Entity\Scope;
use App\Entity\SecurityControl;
use App\Entity\Threat;
use App\Entity\User;
use App\Entity\Vulnerability;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\ConstraintViolationListInterface;

final class ApiResponseFactory
{
    /** @return array<string, mixed> */
    public function framework(Framework $framework): array
    {
        return ['id' => $framework->getId(), 'name' => $framework->getName(), 'version' => $framework->getVersion(), 'description' => $framework->getDescription(), 'publisher' => $framework->getPublisher(), 'status' => $framework->getStatus(), 'requirementCount' => $framework->getRequirements()->count()];
    }

    /** @return array<string, mixed> */
    public function requirement(Requirement $requirement): array
    {
        return ['id' => $requirement->getId(), 'frameworkId' => $requirement->getFramework()->getId(), 'reference' => $requirement->getReference(), 'title' => $requirement->getTitle(), 'description' => $requirement->getDescription(), 'category' => $requirement->getCategory(), 'parentRequirementId' => $requirement->getParentRequirement()?->getId(), 'status' => $requirement->getStatus()];
    }

    /** @return array<string, mixed> */
    public function complianceAssessment(ComplianceAssessment $assessment): array
    {
        return ['id' => $assessment->getId(), 'framework' => ['id' => $assessment->getFramework()->getId(), 'name' => $assessment->getFramework()->getName(), 'version' => $assessment->getFramework()->getVersion()], 'scope' => ['id' => $assessment->getScope()->getId(), 'name' => $assessment->getScope()->getName()], 'assessor' => $this->userSummary($assessment->getAssessor()), 'assessmentDate' => $assessment->getAssessmentDate()->format('Y-m-d'), 'status' => $assessment->getStatus(), 'globalScore' => $assessment->getGlobalScore(), 'resultCount' => $assessment->getResults()->count(), 'createdAt' => $assessment->getCreatedAt()->format(DATE_ATOM), 'updatedAt' => $assessment->getUpdatedAt()->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    public function complianceResult(ComplianceResult $result): array
    {
        return ['id' => $result->getId(), 'assessmentId' => $result->getAssessment()->getId(), 'requirement' => $this->requirement($result->getRequirement()), 'maturityLevel' => $result->getMaturityLevel(), 'complianceStatus' => $result->getComplianceStatus(), 'comment' => $result->getComment(), 'evidence' => $result->getEvidence(), 'remediationAction' => null === $result->getRemediationAction() ? null : ['id' => $result->getRemediationAction()->getId(), 'title' => $result->getRemediationAction()->getTitle()]];
    }

    /** @return array<string, mixed> */
    public function actionPlan(ActionPlan $action): array
    {
        return [
            'id' => $action->getId(), 'title' => $action->getTitle(), 'description' => $action->getDescription(),
            'relatedRisk' => ['id' => $action->getRelatedRisk()->getId(), 'title' => $action->getRelatedRisk()->getTitle()],
            'relatedControl' => null === $action->getRelatedControl() ? null : ['id' => $action->getRelatedControl()->getId(), 'name' => $action->getRelatedControl()->getName()],
            'owner' => $this->userSummary($action->getOwner()), 'priority' => $action->getPriority(), 'status' => $action->getStatus(),
            'startDate' => $action->getStartDate()?->format('Y-m-d'), 'dueDate' => $action->getDueDate()->format('Y-m-d'), 'completionDate' => $action->getCompletionDate()?->format('Y-m-d'),
            'progress' => $action->getProgress(), 'estimatedCost' => $action->getEstimatedCost(), 'actualCost' => $action->getActualCost(),
            'expectedRiskReduction' => $action->getExpectedRiskReduction(), 'evidence' => $action->getEvidence(),
            'createdAt' => $action->getCreatedAt()->format(DATE_ATOM), 'updatedAt' => $action->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function actionComment(ActionComment $comment): array
    {
        return ['id' => $comment->getId(), 'author' => $this->userSummary($comment->getAuthor()), 'message' => $comment->getMessage(), 'createdAt' => $comment->getCreatedAt()->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    public function notification(Notification $notification): array
    {
        return ['id' => $notification->getId(), 'type' => $notification->getType(), 'title' => $notification->getTitle(), 'message' => $notification->getMessage(), 'link' => $notification->getLink(), 'isRead' => $notification->isRead(), 'createdAt' => $notification->getCreatedAt()->format(DATE_ATOM)];
    }

    public function validationError(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()][] = $violation->getMessage();
        }

        return new JsonResponse([
            'code' => 'VALIDATION_ERROR',
            'message' => 'La requête contient des erreurs.',
            'errors' => $errors,
        ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @return array<string, mixed> */
    public function organization(Organization $organization): array
    {
        return [
            'id' => $organization->getId(),
            'name' => $organization->getName(),
            'description' => $organization->getDescription(),
            'status' => $organization->getStatus(),
            'riskThresholds' => $organization->getRiskThresholds(),
            'createdAt' => $organization->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $organization->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function user(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'roles' => $user->getRoles(),
            'status' => $user->getStatus(),
            'organization' => $this->organization($user->getOrganization()),
            'createdAt' => $user->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $user->getUpdatedAt()->format(DATE_ATOM),
            'lastLoginAt' => $user->getLastLoginAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function scope(Scope $scope): array
    {
        return ['id' => $scope->getId(), 'name' => $scope->getName(), 'description' => $scope->getDescription(), 'type' => $scope->getType(), 'parentScopeId' => $scope->getParentScope()?->getId(), 'owner' => null === $scope->getOwner() ? null : $this->userSummary($scope->getOwner()), 'status' => $scope->getStatus()];
    }

    /** @return array<string, mixed> */
    public function asset(Asset $asset): array
    {
        return ['id' => $asset->getId(), 'name' => $asset->getName(), 'description' => $asset->getDescription(), 'type' => $asset->getType(), 'criticality' => $asset->getCriticality(), 'confidentiality' => $asset->getConfidentiality(), 'integrity' => $asset->getIntegrity(), 'availability' => $asset->getAvailability(), 'owner' => null === $asset->getOwner() ? null : $this->userSummary($asset->getOwner()), 'scope' => ['id' => $asset->getScope()->getId(), 'name' => $asset->getScope()->getName()], 'relatedAssets' => array_map(fn (Asset $related): array => ['id' => $related->getId(), 'name' => $related->getName()], $asset->getRelatedAssets()->toArray()), 'status' => $asset->getStatus(), 'createdAt' => $asset->getCreatedAt()->format(DATE_ATOM), 'updatedAt' => $asset->getUpdatedAt()->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    public function threat(Threat $threat): array
    {
        return ['id' => $threat->getId(), 'name' => $threat->getName(), 'description' => $threat->getDescription(), 'category' => $threat->getCategory(), 'source' => $threat->getSource(), 'status' => $threat->getStatus()];
    }

    /** @return array<string, mixed> */
    public function vulnerability(Vulnerability $vulnerability): array
    {
        return ['id' => $vulnerability->getId(), 'name' => $vulnerability->getName(), 'description' => $vulnerability->getDescription(), 'category' => $vulnerability->getCategory(), 'severity' => $vulnerability->getSeverity(), 'affectedAssets' => array_map(fn (Asset $asset): array => ['id' => $asset->getId(), 'name' => $asset->getName()], $vulnerability->getAffectedAssets()->toArray()), 'status' => $vulnerability->getStatus()];
    }

    /** @return array<string, mixed> */
    public function securityControl(SecurityControl $control): array
    {
        return ['id' => $control->getId(), 'name' => $control->getName(), 'description' => $control->getDescription(), 'category' => $control->getCategory(), 'effectiveness' => $control->getEffectiveness(), 'implementationStatus' => $control->getImplementationStatus(), 'owner' => null === $control->getOwner() ? null : $this->userSummary($control->getOwner())];
    }

    /** @return array<string, mixed> */
    public function riskScenario(RiskScenario $risk): array
    {
        return [
            'id' => $risk->getId(), 'title' => $risk->getTitle(), 'description' => $risk->getDescription(),
            'scope' => ['id' => $risk->getScope()->getId(), 'name' => $risk->getScope()->getName()],
            'asset' => ['id' => $risk->getAsset()->getId(), 'name' => $risk->getAsset()->getName()],
            'threat' => ['id' => $risk->getThreat()->getId(), 'name' => $risk->getThreat()->getName()],
            'vulnerabilities' => array_map(fn (Vulnerability $item): array => ['id' => $item->getId(), 'name' => $item->getName()], $risk->getVulnerabilities()->toArray()),
            'currentControls' => array_map(fn (SecurityControl $item): array => ['id' => $item->getId(), 'name' => $item->getName(), 'effectiveness' => $item->getEffectiveness()], $risk->getCurrentControls()->toArray()),
            'riskOwner' => $this->userSummary($risk->getRiskOwner()),
            'likelihood' => $risk->getLikelihood(), 'impact' => $risk->getImpact(), 'grossRiskScore' => $risk->getGrossRiskScore(),
            'currentLikelihood' => $risk->getCurrentLikelihood(), 'currentImpact' => $risk->getCurrentImpact(), 'currentRiskScore' => $risk->getCurrentRiskScore(),
            'residualLikelihood' => $risk->getResidualLikelihood(), 'residualImpact' => $risk->getResidualImpact(), 'residualRiskScore' => $risk->getResidualRiskScore(),
            'treatmentDecision' => $risk->getTreatmentDecision(), 'status' => $risk->getStatus(), 'reviewDate' => $risk->getReviewDate()?->format('Y-m-d'),
            'createdAt' => $risk->getCreatedAt()->format(DATE_ATOM), 'updatedAt' => $risk->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array{id: int|null, email: string, firstName: string, lastName: string} */
    private function userSummary(User $user): array
    {
        return ['id' => $user->getId(), 'email' => $user->getEmail(), 'firstName' => $user->getFirstName(), 'lastName' => $user->getLastName()];
    }
}
