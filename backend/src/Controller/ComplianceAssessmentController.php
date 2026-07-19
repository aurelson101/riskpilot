<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Api\Dto\ComplianceAssessmentInput;
use App\Api\Dto\ComplianceResultInput;
use App\Api\JsonInputMapper;
use App\Application\CurrentUser;
use App\Application\NotificationService;
use App\Entity\ComplianceAssessment;
use App\Entity\ComplianceResult;
use App\Entity\User;
use App\Repository\ActionPlanRepository;
use App\Repository\ComplianceAssessmentRepository;
use App\Repository\ComplianceResultRepository;
use App\Repository\FrameworkRepository;
use App\Repository\RequirementRepository;
use App\Repository\ScopeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ComplianceAssessmentController
{
    public function __construct(
        private ComplianceAssessmentRepository $assessments, private ComplianceResultRepository $results,
        private FrameworkRepository $frameworks, private RequirementRepository $requirements,
        private ScopeRepository $scopes, private UserRepository $users, private ActionPlanRepository $actions,
        private CurrentUser $currentUser, private EntityManagerInterface $entityManager,
        private JsonInputMapper $mapper, private ApiResponseFactory $responses, private NotificationService $notifications,
    ) {
    }

    #[Route('/api/compliance-assessments', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->responses->complianceAssessment(...), $this->assessments->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/api/compliance-assessments/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $item = $this->assessments->findOneVisibleTo($id, $this->currentUser->get());

        return null === $item ? $this->notFound() : new JsonResponse($this->responses->complianceAssessment($item));
    }

    #[Route('/api/compliance-assessments', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->canAssess($this->currentUser->get())) {
            return $this->forbidden();
        }

        return $this->saveAssessment(null, $request);
    }

    #[Route('/api/compliance-assessments/{id<\d+>}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $item = $this->assessments->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $item) {
            return $this->notFound();
        } if (!$this->canEdit($item)) {
            return $this->forbidden();
        }

        return $this->saveAssessment($item, $request);
    }

    #[Route('/api/compliance-assessments/{id<\d+>}/results', methods: ['GET'])]
    public function assessmentResults(int $id): JsonResponse
    {
        $assessment = $this->assessments->findOneVisibleTo($id, $this->currentUser->get());

        return null === $assessment ? $this->notFound() : new JsonResponse(array_map($this->responses->complianceResult(...), $this->results->findForAssessment($assessment)));
    }

    #[Route('/api/compliance-results/{id<\d+>}', methods: ['PUT'])]
    public function updateResult(int $id, Request $request): JsonResponse
    {
        $result = $this->results->find($id);
        if (!$result instanceof ComplianceResult || null === $this->assessments->findOneVisibleTo((int) $result->getAssessment()->getId(), $this->currentUser->get())) {
            return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Résultat introuvable.'], 404);
        }
        if (!$this->canEdit($result->getAssessment())) {
            return $this->forbidden();
        }
        [$input, $violations] = $this->mapper->map($request, ComplianceResultInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }
        $action = null === $input->remediationActionId ? null : $this->actions->findOneVisibleTo($input->remediationActionId, $this->currentUser->get());
        if (null !== $input->remediationActionId && null === $action) {
            return new JsonResponse(['code' => 'INVALID_ACTION', 'message' => 'Action corrective invalide.'], 422);
        }
        $result->setMaturityLevel($input->maturityLevel)->setComplianceStatus($input->complianceStatus)->setComment($input->comment)->setEvidence($input->evidence)->setRemediationAction($action);
        $result->getAssessment()->recalculateScore();
        $this->entityManager->flush();

        return new JsonResponse($this->responses->complianceResult($result));
    }

    private function saveAssessment(?ComplianceAssessment $item, Request $request): JsonResponse
    {
        [$input, $violations] = $this->mapper->map($request, ComplianceAssessmentInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }
        $actor = $this->currentUser->get();
        $framework = null === $input->frameworkId ? null : $this->frameworks->find($input->frameworkId);
        $scope = null === $input->scopeId ? null : $this->scopes->findOneVisibleTo($input->scopeId, $actor);
        $assessor = null === $input->assessorId ? null : $this->users->findOneVisibleTo($input->assessorId, $actor);
        if (null === $framework || null === $scope || null === $assessor) {
            return new JsonResponse(['code' => 'INVALID_RELATION', 'message' => 'Une ou plusieurs relations sont invalides.'], 422);
        }
        $created = null === $item;
        $previousStatus = $item?->getStatus();
        $item ??= new ComplianceAssessment($actor->getOrganization(), $framework, $scope, $assessor, new \DateTimeImmutable((string) $input->assessmentDate));
        if (!$created && $item->getFramework() !== $framework) {
            return new JsonResponse(['code' => 'FRAMEWORK_IMMUTABLE', 'message' => 'Le référentiel ne peut pas être remplacé après le lancement.'], 422);
        }
        $item->setScope($scope)->setAssessor($assessor)->setAssessmentDate(new \DateTimeImmutable((string) $input->assessmentDate))->setStatus($input->status);
        $this->entityManager->persist($item);
        if ($created) {
            foreach ($this->requirements->findBy(['framework' => $framework, 'status' => 'ACTIVE']) as $requirement) {
                $this->entityManager->persist(new ComplianceResult($item, $requirement));
            }
        }
        $item->recalculateScore();
        if ('COMPLETED' === $input->status && 'COMPLETED' !== $previousStatus) {
            $this->notifications->notify($assessor, 'COMPLIANCE_ASSESSMENT_COMPLETED', 'Évaluation de conformité terminée', sprintf('L’évaluation %s %s est terminée avec un score de %.2f%%.', $framework->getName(), $framework->getVersion(), $item->getGlobalScore()), '/compliance');
        }
        $this->entityManager->flush();

        return new JsonResponse($this->responses->complianceAssessment($item), $created ? 201 : 200);
    }

    private function canAssess(User $actor): bool
    {
        return [] !== array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_RISK_MANAGER, User::ROLE_AUDITOR], $actor->getRoles());
    }

    private function canEdit(ComplianceAssessment $assessment): bool
    {
        $actor = $this->currentUser->get();

        return $assessment->getAssessor() === $actor || $this->canAssess($actor);
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['code' => 'FORBIDDEN', 'message' => 'Droits insuffisants pour cette évaluation.'], 403);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Évaluation introuvable.'], 404);
    }
}
