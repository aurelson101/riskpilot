<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\ActionPlan;
use App\Entity\ComplianceAssessment;
use App\Entity\OperationalRecord;
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
    public function myTasks(): JsonResponse
    {
        $user = $this->currentUser->get();
        $tasks = array_map(fn (OperationalRecord $item): array => $this->task($item->getId(), $item->getTitle(), $item->getStatus(), $item->getDueAt(), '/operations', 'OPERATIONAL'), $this->records->findOpenTasks($user));
        foreach ($this->entityManager->getRepository(ActionPlan::class)->findBy(['organization' => $user->getOrganization(), 'owner' => $user], ['dueDate' => 'ASC']) as $action) {
            if (!in_array($action->getStatus(), ['COMPLETED', 'CANCELLED'], true)) {
                $tasks[] = $this->task($action->getId(), $action->getTitle(), $action->getStatus(), $action->getDueDate(), '/actions', 'ACTION');
            }
        }
        foreach ($this->entityManager->getRepository(ComplianceAssessment::class)->findBy(['organization' => $user->getOrganization(), 'assessor' => $user], ['assessmentDate' => 'ASC']) as $assessment) {
            if (!in_array($assessment->getStatus(), ['COMPLETED', 'ARCHIVED'], true)) {
                $tasks[] = $this->task($assessment->getId(), $assessment->getFramework()->getName(), $assessment->getStatus(), $assessment->getAssessmentDate(), '/compliance', 'ASSESSMENT');
            }
        }
        usort($tasks, static fn (array $a, array $b): int => strcmp((string) ($a['dueAt'] ?? '9999'), (string) ($b['dueAt'] ?? '9999')));

        return new JsonResponse(['items' => $tasks, 'total' => count($tasks)]);
    }

    #[Route('/compliance-trajectory', methods: ['GET'])]
    public function trajectory(): JsonResponse
    {
        $programs = $this->records->findForOrganization($this->currentUser->get()->getOrganization(), 'COMPLIANCE_PROGRAM');

        return new JsonResponse(array_map(function (OperationalRecord $program): array {
            $details = $program->getDetails();
            $target = max(1, (int) ($details['targetScore'] ?? 100));
            $current = max(0, min(100, (int) ($details['currentScore'] ?? 0)));
            try {
                $start = new \DateTimeImmutable((string) ($details['startDate'] ?? $program->getCreatedAt()->format('Y-m-d')));
            } catch (\Exception) {
                $start = $program->getCreatedAt();
            }
            $end = $program->getDueAt() ?? new \DateTimeImmutable('+90 days');
            $duration = max(1, $end->getTimestamp() - $start->getTimestamp());
            $expected = min($target, (int) round($target * max(0, min(1, (time() - $start->getTimestamp()) / $duration))));

            return ['id' => $program->getId(), 'title' => $program->getTitle(), 'current' => $current, 'target' => $target, 'expected' => $expected, 'atRisk' => $current + 5 < $expected, 'dueAt' => $end->format(DATE_ATOM)];
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
        $record->update((string) ($data['title'] ?? $record->getTitle()), (string) ($data['status'] ?? $record->getStatus()), $this->details($data, $record->getDetails()), $owner, $dueAt);
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
    private function task(?int $id, string $title, string $status, ?\DateTimeImmutable $dueAt, string $link, string $source): array
    {
        return compact('id', 'title', 'status', 'link', 'source') + ['dueAt' => $dueAt?->format(DATE_ATOM), 'overdue' => null !== $dueAt && $dueAt < new \DateTimeImmutable()];
    }

    private function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code], $status);
    }
}
