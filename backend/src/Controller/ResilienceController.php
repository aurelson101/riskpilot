<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\ContinuityProcess;
use App\Entity\SecurityIncident;
use App\Entity\User;
use App\Repository\ActionPlanRepository;
use App\Repository\AssetRepository;
use App\Repository\ContinuityProcessRepository;
use App\Repository\RiskScenarioRepository;
use App\Repository\ScopeRepository;
use App\Repository\SecurityIncidentRepository;
use App\Repository\ThirdPartyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/resilience')]
final readonly class ResilienceController
{
    public function __construct(private SecurityIncidentRepository $incidents, private ContinuityProcessRepository $processes, private UserRepository $users, private ScopeRepository $scopes, private AssetRepository $assets, private ThirdPartyRepository $thirdParties, private RiskScenarioRepository $risks, private ActionPlanRepository $actions, private CurrentUser $currentUser, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/incidents', methods: ['GET'])]
    public function incidents(): JsonResponse
    {
        return new JsonResponse(array_map($this->incidentResponse(...), $this->incidents->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/incidents', methods: ['POST'])]
    public function createIncident(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        } $data = $request->toArray();
        $owner = $this->users->findOneVisibleTo((int) ($data['ownerId'] ?? 0), $this->currentUser->get());
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        } try {
            $incident = new SecurityIncident($this->currentUser->get()->getOrganization(), $owner, (string) ($data['title'] ?? ''), (string) ($data['description'] ?? ''), (string) ($data['severity'] ?? ''), new \DateTimeImmutable((string) ($data['detectedAt'] ?? 'now')));
            $incident->addTimelineEvent('Incident déclaré', $this->currentUser->get());
            $this->entityManager->persist($incident);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->incidentResponse($incident), 201);
    }

    #[Route('/incidents/{id<\d+>}', methods: ['PUT'])]
    public function updateIncident(int $id, Request $request): JsonResponse
    {
        $incident = $this->incidents->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $incident || !$this->canManage()) {
            return null === $incident ? $this->notFound() : $this->forbidden();
        } $data = $request->toArray();
        $actor = $this->currentUser->get();
        $assets = $this->relations((array) ($data['assetIds'] ?? []), fn (int $relationId): mixed => $this->assets->findOneVisibleTo($relationId, $actor));
        $thirdParties = $this->relations((array) ($data['thirdPartyIds'] ?? []), fn (int $relationId): mixed => $this->thirdParties->findOneVisibleTo($relationId, $actor));
        $risks = $this->relations((array) ($data['riskIds'] ?? []), fn (int $relationId): mixed => $this->risks->findOneVisibleTo($relationId, $actor));
        $actions = $this->relations((array) ($data['actionIds'] ?? []), fn (int $relationId): mixed => $this->actions->findOneVisibleTo($relationId, $actor));
        if (null === $assets || null === $thirdParties || null === $risks || null === $actions) {
            return $this->invalid('Relation étrangère ou invalide.');
        } try {
            $incident->update((string) ($data['status'] ?? 'DETECTED'), (array) ($data['impacts'] ?? []), $this->strings((array) ($data['evidence'] ?? [])), (bool) ($data['regulatoryNotificationRequired'] ?? false), empty($data['notifiedAt']) ? null : new \DateTimeImmutable((string) $data['notifiedAt']), isset($data['lessonsLearned']) ? (string) $data['lessonsLearned'] : null, $assets, $thirdParties, $risks, $actions);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->incidentResponse($incident));
    }

    #[Route('/incidents/{id<\d+>}/timeline', methods: ['POST'])]
    public function timeline(int $id, Request $request): JsonResponse
    {
        $incident = $this->incidents->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $incident || !$this->canManage()) {
            return null === $incident ? $this->notFound() : $this->forbidden();
        } try {
            $incident->addTimelineEvent((string) ($request->toArray()['event'] ?? ''), $this->currentUser->get());
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->incidentResponse($incident));
    }

    #[Route('/continuity-processes', methods: ['GET'])]
    public function processes(): JsonResponse
    {
        return new JsonResponse(array_map($this->processResponse(...), $this->processes->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/continuity-processes', methods: ['POST'])]
    public function createProcess(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        } $data = $request->toArray();
        $actor = $this->currentUser->get();
        $scope = $this->scopes->findOneVisibleTo((int) ($data['scopeId'] ?? 0), $actor);
        $owner = $this->users->findOneVisibleTo((int) ($data['ownerId'] ?? 0), $actor);
        if (null === $scope || null === $owner) {
            return $this->invalid('Périmètre ou responsable invalide.');
        } try {
            $process = new ContinuityProcess($actor->getOrganization(), $scope, $owner, (string) ($data['name'] ?? ''), (string) ($data['criticality'] ?? ''), (int) ($data['mtpdHours'] ?? 0), (int) ($data['rtoHours'] ?? 0), (int) ($data['rpoHours'] ?? 0), $this->strings((array) ($data['dependencies'] ?? [])), (string) ($data['businessImpact'] ?? ''));
            $process->setPlans(isset($data['bcpProcedure']) ? (string) $data['bcpProcedure'] : null, isset($data['drpProcedure']) ? (string) $data['drpProcedure'] : null, empty($data['nextExerciseAt']) ? null : new \DateTimeImmutable((string) $data['nextExerciseAt']));
            $this->entityManager->persist($process);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->processResponse($process), 201);
    }

    #[Route('/continuity-processes/{id<\d+>}/exercises', methods: ['POST'])]
    public function exercise(int $id, Request $request): JsonResponse
    {
        $process = $this->processes->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $process || !$this->canManage()) {
            return null === $process ? $this->notFound() : $this->forbidden();
        } $data = $request->toArray();
        try {
            $process->recordExercise(new \DateTimeImmutable((string) ($data['date'] ?? 'now')), (string) ($data['scenario'] ?? ''), $this->strings((array) ($data['participants'] ?? [])), (string) ($data['result'] ?? ''), $this->strings((array) ($data['gaps'] ?? [])), $this->strings((array) ($data['improvements'] ?? [])));
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->processResponse($process));
    }

    /** @return array<string, mixed> */
    private function incidentResponse(SecurityIncident $item): array
    {
        return ['id' => $item->getId(), 'title' => $item->getTitle(), 'description' => $item->getDescription(), 'severity' => $item->getSeverity(), 'status' => $item->getStatus(), 'owner' => $this->user($item->getOwner()), 'detectedAt' => $item->getDetectedAt()->format(DATE_ATOM), 'closedAt' => $item->getClosedAt()?->format(DATE_ATOM), 'impacts' => $item->getImpacts(), 'timeline' => $item->getTimeline(), 'evidence' => $item->getEvidence(), 'regulatoryNotificationRequired' => $item->isRegulatoryNotificationRequired(), 'notifiedAt' => $item->getNotifiedAt()?->format(DATE_ATOM), 'lessonsLearned' => $item->getLessonsLearned(), 'assetIds' => array_map(static fn ($value): ?int => $value->getId(), $item->getAssets()->toArray()), 'thirdPartyIds' => array_map(static fn ($value): ?int => $value->getId(), $item->getThirdParties()->toArray()), 'riskIds' => array_map(static fn ($value): ?int => $value->getId(), $item->getRisks()->toArray()), 'actionIds' => array_map(static fn ($value): ?int => $value->getId(), $item->getActions()->toArray())];
    }

    /** @return array<string, mixed> */
    private function processResponse(ContinuityProcess $item): array
    {
        return ['id' => $item->getId(), 'name' => $item->getName(), 'criticality' => $item->getCriticality(), 'scope' => ['id' => $item->getScope()->getId(), 'name' => $item->getScope()->getName()], 'owner' => $this->user($item->getOwner()), 'mtpdHours' => $item->getMtpdHours(), 'rtoHours' => $item->getRtoHours(), 'rpoHours' => $item->getRpoHours(), 'dependencies' => $item->getDependencies(), 'businessImpact' => $item->getBusinessImpact(), 'bcpProcedure' => $item->getBcpProcedure(), 'drpProcedure' => $item->getDrpProcedure(), 'nextExerciseAt' => $item->getNextExerciseAt()?->format('Y-m-d'), 'exercises' => $item->getExercises()];
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<object>|null
     */
    private function relations(array $ids, callable $finder): ?array
    {
        $items = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $item = $finder($id);
            if (null === $item) {
                return null;
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $values)));
    }

    /** @return array{id: int|null, name: string} */
    private function user(User $user): array
    {
        return ['id' => $user->getId(), 'name' => trim($user->getFirstName().' '.$user->getLastName())];
    }

    private function canManage(): bool
    {
        return [] !== array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_RISK_MANAGER, User::ROLE_AUDITOR, User::ROLE_ACTION_OWNER], $this->currentUser->get()->getRoles());
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['code' => 'FORBIDDEN', 'message' => 'Droits insuffisants.'], 403);
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_INPUT', 'message' => $message], 422);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Ressource introuvable.'], 404);
    }
}
