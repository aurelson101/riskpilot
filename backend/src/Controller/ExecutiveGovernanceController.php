<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\ExecutiveGovernanceRecord;
use App\Entity\User;
use App\Repository\ActionPlanRepository;
use App\Repository\ComplianceAssessmentRepository;
use App\Repository\ExecutiveGovernanceRecordRepository;
use App\Repository\RiskScenarioRepository;
use App\Repository\SecurityControlRepository;
use App\Repository\ThirdPartyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/executive-governance')] final readonly class ExecutiveGovernanceController
{
    public function __construct(private ExecutiveGovernanceRecordRepository $records, private UserRepository $users, private RiskScenarioRepository $risks, private SecurityControlRepository $controls, private ComplianceAssessmentRepository $assessments, private ThirdPartyRepository $thirdParties, private ActionPlanRepository $actions, private CurrentUser $currentUser, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/records', methods: ['GET'])]
    public function records(): JsonResponse
    {
        return new JsonResponse(array_map($this->response(...), $this->records->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/records', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        } $data = $request->toArray();
        $actor = $this->currentUser->get();
        $owner = $this->users->findOneVisibleTo((int) ($data['ownerId'] ?? 0), $actor);
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        } try {
            $record = new ExecutiveGovernanceRecord($actor->getOrganization(), $owner, (string) ($data['type'] ?? ''), (string) ($data['title'] ?? ''), (array) ($data['details'] ?? []), (string) ($data['status'] ?? 'DRAFT'), empty($data['reviewAt']) ? null : new \DateTimeImmutable((string) $data['reviewAt']));
            $this->entityManager->persist($record);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->response($record), 201);
    }

    #[Route('/vision-360', methods: ['GET'])]
    public function vision(): JsonResponse
    {
        $actor = $this->currentUser->get();
        $risks = $this->risks->findVisibleTo($actor);
        $controls = $this->controls->findVisibleTo($actor);
        $assessments = $this->assessments->findVisibleTo($actor);
        $thirdParties = $this->thirdParties->findVisibleTo($actor);
        $actions = $this->actions->findVisibleTo($actor);
        $losses = array_values(array_filter(array_map(static fn (ExecutiveGovernanceRecord $item): ?array => 'FINANCIAL_SCENARIO' === $item->getType() ? $item->getDetails() : null, $this->records->findVisibleTo($actor))));

        return new JsonResponse(['risks' => ['total' => count($risks), 'critical' => count(array_filter($risks, static fn ($item): bool => $item->getCurrentRiskScore() >= 16))], 'controls' => ['total' => count($controls), 'implemented' => count(array_filter($controls, static fn ($item): bool => 'IMPLEMENTED' === $item->getImplementationStatus()))], 'compliance' => ['assessments' => count($assessments), 'averageScore' => [] === $assessments ? 0 : round(array_sum(array_map(static fn ($item): float => $item->getGlobalScore(), $assessments)) / count($assessments), 2)], 'thirdParties' => ['total' => count($thirdParties), 'critical' => count(array_filter($thirdParties, static fn ($item): bool => 'CRITICAL' === $item->getCriticality()))], 'actions' => ['total' => count($actions), 'overdue' => count(array_filter($actions, static fn ($item): bool => $item->getDueDate() < new \DateTimeImmutable('today') && 'DONE' !== $item->getStatus()))], 'financialScenarios' => $losses]);
    }

    /** @return array<string, mixed> */
    private function response(ExecutiveGovernanceRecord $item): array
    {
        return ['id' => $item->getId(), 'type' => $item->getType(), 'title' => $item->getTitle(), 'details' => $item->getDetails(), 'status' => $item->getStatus(), 'reviewAt' => $item->getReviewAt()?->format('Y-m-d'), 'owner' => ['id' => $item->getOwner()->getId(), 'name' => trim($item->getOwner()->getFirstName().' '.$item->getOwner()->getLastName())]];
    }

    private function canManage(): bool
    {
        return [] !== array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_RISK_MANAGER, User::ROLE_AUDITOR], $this->currentUser->get()->getRoles());
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['code' => 'FORBIDDEN', 'message' => 'Droits insuffisants.'], 403);
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_INPUT', 'message' => $message], 422);
    }
}
