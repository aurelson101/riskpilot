<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\ActionPlan;
use App\Entity\ComplianceAssessment;
use App\Entity\OperationalRecord;
use App\Entity\RiskScenario;
use App\Entity\SecurityControl;
use App\Entity\SecurityIncident;
use App\Entity\ThirdParty;
use App\Entity\User;
use App\Repository\OperationalRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/operations')]
final readonly class OperationalWorkspaceController
{
    public function __construct(private OperationalRecordRepository $records, private CurrentUser $currentUser, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/records', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $type = $request->query->get('type');
        if (null !== $type && !in_array($type, OperationalRecord::TYPES, true)) {
            return $this->error('INVALID_TYPE', 422);
        }

        $actor = $this->currentUser->get();
        $records = $this->records->findForOrganization($actor->getOrganization(), $type);
        $records = array_values(array_filter($records, static function (OperationalRecord $record) use ($actor): bool {
            if ('SAVED_VIEW' !== $record->getType()) {
                return true;
            }

            return $record->getOwner() === $actor || true === ($record->getDetails()['shared'] ?? false);
        }));

        return new JsonResponse(array_map($this->serialize(...), $records));
    }

    #[Route('/records', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        if (in_array((string) ($data['type'] ?? ''), OperationalRecord::SYSTEM_MANAGED_TYPES, true)) {
            return $this->error('SYSTEM_MANAGED_TYPE', 403);
        }
        try {
            $record = new OperationalRecord($this->currentUser->get()->getOrganization(), (string) ($data['type'] ?? ''), (string) ($data['title'] ?? ''), $this->details($data));
            $this->apply($record, $data);
        } catch (\Exception $error) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => $error->getMessage()], 422);
        }
        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($record), 201);
    }

    #[Route('/records/{id<\d+>}', methods: ['PUT'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function update(int $id, Request $request): JsonResponse
    {
        $record = $this->records->findOneVisible($id, $this->currentUser->get()->getOrganization());
        if (null === $record) {
            return $this->error('NOT_FOUND', 404);
        }
        if (in_array($record->getType(), OperationalRecord::SYSTEM_MANAGED_TYPES, true)) {
            return $this->error('IMMUTABLE_RECORD', 409);
        }
        try {
            $this->apply($record, $request->toArray());
        } catch (\Exception $error) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => $error->getMessage()], 422);
        }
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($record));
    }

    #[Route('/my-tasks', methods: ['GET'])]
    public function myTasks(Request $request): JsonResponse
    {
        $user = $this->currentUser->get();
        $tasks = array_map(fn (OperationalRecord $item): array => $this->task($item->getId(), $item->getTitle(), $item->getStatus(), $item->getDueAt(), '/operations', 'OPERATIONAL', $item->getDetails()['priority'] ?? 'MEDIUM'), $this->records->findOpenTasks($user));
        foreach ($this->entityManager->getRepository(ActionPlan::class)->findBy(['organization' => $user->getOrganization(), 'owner' => $user], ['dueDate' => 'ASC']) as $action) {
            if (!in_array($action->getStatus(), ['COMPLETED', 'CANCELLED'], true)) {
                $tasks[] = $this->task($action->getId(), $action->getTitle(), $action->getStatus(), $action->getDueDate(), '/actions', 'ACTION', $action->getPriority());
            }
        }
        foreach ($this->entityManager->getRepository(ComplianceAssessment::class)->findBy(['organization' => $user->getOrganization(), 'assessor' => $user], ['assessmentDate' => 'ASC']) as $assessment) {
            if (!in_array($assessment->getStatus(), ['COMPLETED', 'ARCHIVED'], true)) {
                $tasks[] = $this->task($assessment->getId(), $assessment->getFramework()->getName(), $assessment->getStatus(), $assessment->getAssessmentDate(), '/compliance', 'ASSESSMENT');
            }
        }
        foreach ($this->entityManager->getRepository(RiskScenario::class)->findBy(['organization' => $user->getOrganization(), 'riskOwner' => $user], ['reviewDate' => 'ASC']) as $risk) {
            if (!in_array($risk->getStatus(), ['CLOSED', 'ARCHIVED'], true)) {
                $tasks[] = $this->task($risk->getId(), $risk->getTitle(), $risk->getStatus(), $risk->getReviewDate(), '/risks', 'RISK', $risk->getCurrentRiskScore() >= 15 ? 'CRITICAL' : 'HIGH');
            }
        }
        foreach ($this->entityManager->getRepository(SecurityControl::class)->findBy(['organization' => $user->getOrganization(), 'owner' => $user]) as $control) {
            if ('IMPLEMENTED' !== $control->getImplementationStatus()) {
                $tasks[] = $this->task($control->getId(), $control->getName(), $control->getImplementationStatus(), null, '/compliance', 'CONTROL', 'MEDIUM');
            }
        }
        foreach ($this->entityManager->getRepository(ThirdParty::class)->findBy(['organization' => $user->getOrganization(), 'owner' => $user], ['nextAssessmentAt' => 'ASC']) as $thirdParty) {
            $tasks[] = $this->task($thirdParty->getId(), $thirdParty->getName(), $thirdParty->getStatus(), $thirdParty->getNextAssessmentAt(), '/third-parties', 'THIRD_PARTY', 'HIGH');
        }
        foreach ($this->entityManager->getRepository(SecurityIncident::class)->findBy(['organization' => $user->getOrganization(), 'owner' => $user], ['detectedAt' => 'ASC']) as $incident) {
            if ('CLOSED' !== $incident->getStatus()) {
                $tasks[] = $this->task($incident->getId(), $incident->getTitle(), $incident->getStatus(), null, '/resilience', 'INCIDENT', $incident->getSeverity());
            }
        }
        usort($tasks, static fn (array $a, array $b): int => strcmp((string) ($a['dueAt'] ?? '9999'), (string) ($b['dueAt'] ?? '9999')));

        $query = mb_strtolower(trim((string) $request->query->get('q', '')));
        $source = strtoupper(trim((string) $request->query->get('source', '')));
        $status = strtoupper(trim((string) $request->query->get('status', '')));
        $tasks = array_values(array_filter($tasks, static fn (array $task): bool => ('' === $query || str_contains(mb_strtolower((string) $task['title']), $query))
            && ('' === $source || $task['source'] === $source)
            && ('' === $status || $task['status'] === $status)
        ));
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(1, $request->query->getInt('limit', 20)));
        $total = count($tasks);

        return new JsonResponse(['items' => array_slice($tasks, ($page - 1) * $limit, $limit), 'page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => max(1, (int) ceil($total / $limit))]);
    }

    #[Route('/tasks/{id<\d+>}/complete', methods: ['POST'])] #[IsGranted(User::ROLE_VIEWER)]
    public function completeTask(int $id): JsonResponse
    {
        $record = $this->records->findOneVisible($id, $this->currentUser->get()->getOrganization());
        if (null === $record || 'TASK' !== $record->getType() || $record->getOwner() !== $this->currentUser->get()) {
            return $this->error('NOT_FOUND', 404);
        }
        $record->update($record->getTitle(), 'COMPLETED', $record->getDetails(), $record->getOwner(), $record->getDueAt());
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($record));
    }

    #[Route('/tasks/{id<\d+>}/delegate', methods: ['POST'])] #[IsGranted(User::ROLE_VIEWER)]
    public function delegateTask(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $record = $this->records->findOneVisible($id, $actor->getOrganization());
        if (null === $record || 'TASK' !== $record->getType() || $record->getOwner() !== $actor) {
            return $this->error('NOT_FOUND', 404);
        }
        $data = $request->toArray();
        $criteria = ['organization' => $actor->getOrganization()];
        if (!empty($data['email'])) {
            $criteria['email'] = mb_strtolower(trim((string) $data['email']));
        } else {
            $criteria['id'] = (int) ($data['userId'] ?? 0);
        }
        $delegate = $this->entityManager->getRepository(User::class)->findOneBy($criteria);
        $until = new \DateTimeImmutable((string) ($data['until'] ?? ''));
        if (null === $delegate || $until <= new \DateTimeImmutable() || $until > new \DateTimeImmutable('+90 days')) {
            return $this->error('INVALID_DELEGATION', 422);
        }
        $details = $record->getDetails() + ['delegatedFromId' => $actor->getId(), 'delegatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)];
        $details['delegatedUntil'] = $until->format(DATE_ATOM);
        $record->update($record->getTitle(), $record->getStatus(), $details, $delegate, $record->getDueAt());
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($record));
    }

    #[Route('/compliance-trajectory', methods: ['GET'])]
    public function trajectory(): JsonResponse
    {
        $programs = $this->records->findForOrganization($this->currentUser->get()->getOrganization(), 'COMPLIANCE_PROGRAM');
        $assessments = $this->entityManager->getRepository(ComplianceAssessment::class)->findBy(['organization' => $this->currentUser->get()->getOrganization()], ['assessmentDate' => 'DESC']);

        return new JsonResponse(array_map(function (OperationalRecord $program) use ($assessments): array {
            $details = $program->getDetails();
            $target = max(1, (int) ($details['targetScore'] ?? 100));
            $frameworks = array_values(array_filter(array_map('strval', is_array($details['frameworks'] ?? null) ? $details['frameworks'] : [])));
            $latest = [];
            foreach ($assessments as $assessment) {
                $name = $assessment->getFramework()->getName();
                if (([] === $frameworks || in_array($name, $frameworks, true)) && !isset($latest[$name])) {
                    $latest[$name] = $assessment->getGlobalScore();
                }
            }
            $measured = [] === $latest ? null : (int) round(array_sum($latest) / count($latest));
            $current = max(0, min(100, $measured ?? (int) ($details['currentScore'] ?? 0)));
            try {
                $start = new \DateTimeImmutable((string) ($details['startDate'] ?? $program->getCreatedAt()->format('Y-m-d')));
            } catch (\Exception) {
                $start = $program->getCreatedAt();
            }
            $end = $program->getDueAt() ?? new \DateTimeImmutable('+90 days');
            $duration = max(1, $end->getTimestamp() - $start->getTimestamp());
            $expected = min($target, (int) round($target * max(0, min(1, (time() - $start->getTimestamp()) / $duration))));

            return ['id' => $program->getId(), 'title' => $program->getTitle(), 'current' => $current, 'target' => $target, 'expected' => $expected, 'gap' => max(0, $target - $current), 'atRisk' => $current + 5 < $expected, 'remainingDays' => max(0, (int) ceil(($end->getTimestamp() - time()) / 86400)), 'frameworkScores' => $latest, 'source' => null === $measured ? 'DECLARED' : 'ASSESSMENTS', 'dueAt' => $end->format(DATE_ATOM)];
        }, $programs));
    }

    /** @param array<string, mixed> $data */
    private function apply(OperationalRecord $record, array $data): void
    {
        $owner = $record->getOwner();
        if (array_key_exists('ownerId', $data) && empty($data['ownerId'])) {
            $owner = null;
        } elseif (!empty($data['ownerId'])) {
            $owner = $this->entityManager->getRepository(User::class)->findOneBy(['id' => (int) $data['ownerId'], 'organization' => $this->currentUser->get()->getOrganization()]);
        }
        if (!empty($data['ownerId']) && null === $owner) {
            throw new \InvalidArgumentException('Invalid owner.');
        }
        $dueAt = $record->getDueAt();
        if (array_key_exists('dueAt', $data)) {
            $dueAt = empty($data['dueAt']) ? null : new \DateTimeImmutable((string) $data['dueAt']);
        }
        $details = $this->details($data, $record->getDetails());
        if (null === $owner && !array_key_exists('ownerId', $data)) {
            $owner = $this->automaticOwner($record, $details);
        }
        if ('REPORT_TEMPLATE' === $record->getType()) {
            $this->validateReportTemplate($details);
        }
        $record->update((string) ($data['title'] ?? $record->getTitle()), (string) ($data['status'] ?? $record->getStatus()), $details, $owner, $dueAt);
    }

    /** @param array<string, mixed> $details */
    private function automaticOwner(OperationalRecord $record, array $details): ?User
    {
        $domain = strtoupper((string) ($details['domain'] ?? $record->getType()));
        foreach ($this->records->findForOrganization($this->currentUser->get()->getOrganization(), 'RESPONSIBILITY_RULE') as $rule) {
            $configuration = $rule->getDetails();
            if ('ACTIVE' !== $rule->getStatus() || strtoupper((string) ($configuration['domain'] ?? '')) !== $domain) {
                continue;
            }
            $role = (string) ($configuration['defaultRole'] ?? '');
            foreach ($this->entityManager->getRepository(User::class)->findBy(['organization' => $this->currentUser->get()->getOrganization(), 'status' => User::STATUS_ACTIVE], ['id' => 'ASC']) as $candidate) {
                if (in_array($role, $candidate->getAssignedRoles(), true)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $details */
    private function validateReportTemplate(array $details): void
    {
        $allowedBlocks = ['risks', 'actions', 'compliance'];
        $blocks = array_values(array_unique(array_map('strval', is_array($details['blocks'] ?? null) ? $details['blocks'] : [])));
        if ([] === $blocks || [] !== array_diff($blocks, $allowedBlocks)) {
            throw new \InvalidArgumentException('Report blocks must use risks, actions or compliance.');
        }
        $reportType = (string) ($details['reportType'] ?? 'MANAGEMENT_COMMITTEE');
        if (!in_array($reportType, ['MANAGEMENT_COMMITTEE', 'ISMS_REVIEW', 'COMPLIANCE', 'RISK_ANALYSIS', 'TREATMENT_PLAN', 'THIRD_PARTY'], true)) {
            throw new \InvalidArgumentException('Invalid report type.');
        }
        $version = trim((string) ($details['version'] ?? ''));
        if ('' === $version || mb_strlen($version) > 40) {
            throw new \InvalidArgumentException('A valid report version is required.');
        }
        if (true === ($details['approved'] ?? false) && '' === trim((string) ($details['approvedBy'] ?? ''))) {
            throw new \InvalidArgumentException('An approved report template requires an approver.');
        }
        foreach (['decisionText', 'recommendations'] as $field) {
            if (isset($details[$field]) && (!is_string($details[$field]) || mb_strlen($details[$field]) > 5000)) {
                throw new \InvalidArgumentException('Report text is invalid.');
            }
        }
        if (isset($details['filters']) && !is_array($details['filters'])) {
            throw new \InvalidArgumentException('Report filters must be structured.');
        }
        $classification = strtoupper((string) ($details['classification'] ?? 'CONFIDENTIAL'));
        if (!in_array($classification, ['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'], true)) {
            throw new \InvalidArgumentException('Invalid report classification.');
        }
        $period = (array) ($details['period'] ?? ['mode' => 'ALL_TIME']);
        $mode = strtoupper((string) ($period['mode'] ?? 'ALL_TIME'));
        if (!in_array($mode, ['ALL_TIME', 'CALENDAR_YEAR', 'ROLLING_MONTHS', 'CUSTOM'], true)) {
            throw new \InvalidArgumentException('Invalid report period.');
        }
        if ('ROLLING_MONTHS' === $mode && ((int) ($period['months'] ?? 0) < 1 || (int) ($period['months'] ?? 0) > 120)) {
            throw new \InvalidArgumentException('Rolling period must be between 1 and 120 months.');
        }
        if ('CUSTOM' === $mode) {
            $from = new \DateTimeImmutable((string) ($period['from'] ?? ''));
            $until = new \DateTimeImmutable((string) ($period['until'] ?? ''));
            if ($from > $until) {
                throw new \InvalidArgumentException('Report period dates are inconsistent.');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $fallback
     *
     * @return array<string, mixed>
     */
    private function details(array $data, array $fallback = []): array
    {
        return is_array($data['details'] ?? null) ? $data['details'] : $fallback;
    }

    /** @return array<string, mixed> */
    private function serialize(OperationalRecord $r): array
    {
        return ['id' => $r->getId(), 'type' => $r->getType(), 'title' => $r->getTitle(), 'status' => $r->getStatus(), 'details' => $r->getDetails(), 'owner' => null === $r->getOwner() ? null : ['id' => $r->getOwner()->getId(), 'name' => trim($r->getOwner()->getFirstName().' '.$r->getOwner()->getLastName())], 'dueAt' => $r->getDueAt()?->format(DATE_ATOM), 'lastReminderAt' => $r->getLastReminderAt()?->format(DATE_ATOM), 'updatedAt' => $r->getUpdatedAt()->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function task(?int $id, string $title, string $status, ?\DateTimeImmutable $dueAt, string $link, string $source, mixed $priority = 'MEDIUM'): array
    {
        return compact('id', 'title', 'status', 'link', 'source') + ['priority' => strtoupper((string) $priority), 'dueAt' => $dueAt?->format(DATE_ATOM), 'overdue' => null !== $dueAt && $dueAt < new \DateTimeImmutable(), 'quickActions' => 'OPERATIONAL' === $source ? ['complete', 'delegate'] : []];
    }

    private function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code], $status);
    }
}
