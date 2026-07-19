<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\AuditEngagement;
use App\Entity\AuditFinding;
use App\Entity\AuditProgram;
use App\Entity\User;
use App\Repository\AuditProgramRepository;
use App\Repository\ScopeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/audit-management')]
final readonly class AuditManagementController
{
    public function __construct(private AuditProgramRepository $programs, private ScopeRepository $scopes, private UserRepository $users, private CurrentUser $currentUser, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/programs', methods: ['GET'])]
    public function programs(): JsonResponse
    {
        return new JsonResponse(array_map($this->programResponse(...), $this->programs->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/programs', methods: ['POST'])]
    public function createProgram(Request $request): JsonResponse
    {
        if (!$this->canAudit()) {
            return $this->forbidden();
        }
        $data = $request->toArray();
        $actor = $this->currentUser->get();
        $owner = $this->users->findOneVisibleTo((int) ($data['ownerId'] ?? 0), $actor);
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        }
        try {
            $program = new AuditProgram($actor->getOrganization(), $owner, (int) ($data['year'] ?? date('Y')), (string) ($data['title'] ?? ''));
            $program->update((string) ($data['title'] ?? ''), isset($data['objectives']) ? (string) $data['objectives'] : null, (string) ($data['status'] ?? 'DRAFT'), $owner);
            $this->entityManager->persist($program);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->programResponse($program), 201);
    }

    #[Route('/programs/{id<\d+>}', methods: ['GET'])]
    public function showProgram(int $id): JsonResponse
    {
        $program = $this->programs->findOneVisibleTo($id, $this->currentUser->get());

        return null === $program ? $this->notFound('Programme introuvable.') : new JsonResponse($this->programResponse($program, true));
    }

    #[Route('/programs/{id<\d+>}/engagements', methods: ['POST'])]
    public function createEngagement(int $id, Request $request): JsonResponse
    {
        $program = $this->programs->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $program || !$this->canAudit()) {
            return null === $program ? $this->notFound('Programme introuvable.') : $this->forbidden();
        }
        $data = $request->toArray();
        $actor = $this->currentUser->get();
        $scope = $this->scopes->findOneVisibleTo((int) ($data['scopeId'] ?? 0), $actor);
        $lead = $this->users->findOneVisibleTo((int) ($data['leadAuditorId'] ?? 0), $actor);
        if (null === $scope || null === $lead || !$this->hasAuditRole($lead)) {
            return $this->invalid('Périmètre ou auditeur principal invalide.');
        }
        try {
            $engagement = new AuditEngagement($program, $scope, $lead, (string) ($data['title'] ?? ''), (string) ($data['independenceStatement'] ?? ''), new \DateTimeImmutable((string) ($data['startsAt'] ?? 'now')), new \DateTimeImmutable((string) ($data['endsAt'] ?? 'now')));
            $team = $this->team((array) ($data['teamIds'] ?? [$lead->getId()]));
            if (null === $team || !in_array($lead, $team, true)) {
                return $this->invalid('Équipe invalide.');
            } $engagement->update((string) ($data['status'] ?? 'PLANNED'), $team, isset($data['finalReportReference']) ? (string) $data['finalReportReference'] : null);
            $this->entityManager->persist($engagement);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->engagementResponse($engagement), 201);
    }

    #[Route('/engagements/{id<\d+>}/findings', methods: ['POST'])]
    public function createFinding(int $id, Request $request): JsonResponse
    {
        $engagement = $this->engagement($id);
        if (null === $engagement || !$this->canAudit()) {
            return null === $engagement ? $this->notFound('Mission introuvable.') : $this->forbidden();
        }
        $data = $request->toArray();
        $owner = $this->users->findOneVisibleTo((int) ($data['ownerId'] ?? 0), $this->currentUser->get());
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        }
        try {
            $finding = new AuditFinding($engagement, $owner, (string) ($data['type'] ?? ''), (string) ($data['title'] ?? ''), (string) ($data['description'] ?? ''), $this->strings((array) ($data['evidence'] ?? [])), new \DateTimeImmutable((string) ($data['dueAt'] ?? 'now')));
            $this->entityManager->persist($finding);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->findingResponse($finding), 201);
    }

    #[Route('/findings/{id<\d+>}/capa', methods: ['PUT'])]
    public function planCapa(int $id, Request $request): JsonResponse
    {
        $finding = $this->finding($id);
        if (null === $finding || !$this->canManageFinding($finding)) {
            return null === $finding ? $this->notFound('Constat introuvable.') : $this->forbidden();
        } $data = $request->toArray();
        try {
            $finding->planCapa((string) ($data['rootCause'] ?? ''), (string) ($data['correction'] ?? ''), (string) ($data['correctiveAction'] ?? ''), isset($data['preventiveAction']) ? (string) $data['preventiveAction'] : null);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->findingResponse($finding));
    }

    #[Route('/findings/{id<\d+>}/effectiveness-review', methods: ['POST'])]
    public function requestReview(int $id): JsonResponse
    {
        $finding = $this->finding($id);
        if (null === $finding || !$this->canManageFinding($finding)) {
            return null === $finding ? $this->notFound('Constat introuvable.') : $this->forbidden();
        }
        try {
            $finding->requestEffectivenessReview();
            $this->entityManager->flush();
        } catch (\LogicException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->findingResponse($finding));
    }

    #[Route('/findings/{id<\d+>}/effectiveness-decision', methods: ['POST'])]
    public function effectivenessDecision(int $id, Request $request): JsonResponse
    {
        $finding = $this->finding($id);
        if (null === $finding || !$this->canAudit()) {
            return null === $finding ? $this->notFound('Constat introuvable.') : $this->forbidden();
        } $data = $request->toArray();
        try {
            $finding->validateEffectiveness($this->currentUser->get(), (bool) ($data['effective'] ?? false), (string) ($data['conclusion'] ?? ''));
            $this->entityManager->flush();
        } catch (\LogicException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->findingResponse($finding));
    }

    #[Route('/dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        $programs = $this->programs->findVisibleTo($this->currentUser->get());
        $engagements = [];
        $findings = [];
        foreach ($programs as $program) {
            foreach ($program->getEngagements() as $engagement) {
                $engagements[] = $engagement;
                foreach ($engagement->getFindings() as $finding) {
                    $findings[] = $finding;
                }
            }
        }
        $today = new \DateTimeImmutable('today');

        return new JsonResponse(['programs' => count($programs), 'engagements' => count($engagements), 'completedEngagements' => count(array_filter($engagements, static fn (AuditEngagement $item): bool => 'COMPLETED' === $item->getStatus())), 'openFindings' => count(array_filter($findings, static fn (AuditFinding $item): bool => !in_array($item->getStatus(), ['CLOSED', 'REJECTED'], true))), 'overdueFindings' => count(array_filter($findings, static fn (AuditFinding $item): bool => $item->getDueAt() < $today && !in_array($item->getStatus(), ['CLOSED', 'REJECTED'], true)))]);
    }

    private function engagement(int $id): ?AuditEngagement
    {
        $item = $this->entityManager->getRepository(AuditEngagement::class)->find($id);

        return $item instanceof AuditEngagement && $item->getProgram()->getOrganization() === $this->currentUser->get()->getOrganization() ? $item : null;
    }

    private function finding(int $id): ?AuditFinding
    {
        $item = $this->entityManager->getRepository(AuditFinding::class)->find($id);

        return $item instanceof AuditFinding && $item->getEngagement()->getProgram()->getOrganization() === $this->currentUser->get()->getOrganization() ? $item : null;
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<User>|null
     */
    private function team(array $ids): ?array
    {
        $team = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $user = $this->users->findOneVisibleTo($id, $this->currentUser->get());
            if (null === $user) {
                return null;
            }
            $team[] = $user;
        }

        return $team;
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

    private function canAudit(): bool
    {
        return [] !== array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_AUDITOR], $this->currentUser->get()->getRoles());
    }

    private function hasAuditRole(User $user): bool
    {
        return [] !== array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_AUDITOR], $user->getRoles());
    }

    private function canManageFinding(AuditFinding $finding): bool
    {
        return $finding->getOwner() === $this->currentUser->get() || $this->canAudit();
    }

    /** @return array<string, mixed> */
    private function programResponse(AuditProgram $program, bool $detail = false): array
    {
        $response = ['id' => $program->getId(), 'year' => $program->getYear(), 'title' => $program->getTitle(), 'objectives' => $program->getObjectives(), 'status' => $program->getStatus(), 'owner' => $this->user($program->getOwner()), 'engagementCount' => $program->getEngagements()->count()];
        if ($detail) {
            $response['engagements'] = array_map($this->engagementResponse(...), $program->getEngagements()->toArray());
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function engagementResponse(AuditEngagement $engagement): array
    {
        return ['id' => $engagement->getId(), 'title' => $engagement->getTitle(), 'scope' => ['id' => $engagement->getScope()->getId(), 'name' => $engagement->getScope()->getName()], 'leadAuditor' => $this->user($engagement->getLeadAuditor()), 'team' => array_map($this->user(...), $engagement->getTeam()->toArray()), 'independenceStatement' => $engagement->getIndependenceStatement(), 'startsAt' => $engagement->getStartsAt()->format('Y-m-d'), 'endsAt' => $engagement->getEndsAt()->format('Y-m-d'), 'status' => $engagement->getStatus(), 'finalReportReference' => $engagement->getFinalReportReference(), 'findings' => array_map($this->findingResponse(...), $engagement->getFindings()->toArray())];
    }

    /** @return array<string, mixed> */
    private function findingResponse(AuditFinding $finding): array
    {
        return ['id' => $finding->getId(), 'type' => $finding->getType(), 'title' => $finding->getTitle(), 'description' => $finding->getDescription(), 'owner' => $this->user($finding->getOwner()), 'evidence' => $finding->getEvidence(), 'dueAt' => $finding->getDueAt()->format('Y-m-d'), 'status' => $finding->getStatus(), 'rootCause' => $finding->getRootCause(), 'correction' => $finding->getCorrection(), 'correctiveAction' => $finding->getCorrectiveAction(), 'preventiveAction' => $finding->getPreventiveAction(), 'effectivenessConclusion' => $finding->getEffectivenessConclusion(), 'effectivenessValidatedBy' => null === $finding->getEffectivenessValidatedBy() ? null : $this->user($finding->getEffectivenessValidatedBy()), 'effectivenessValidatedAt' => $finding->getEffectivenessValidatedAt()?->format(DATE_ATOM)];
    }

    /** @return array{id: int|null, name: string} */
    private function user(User $user): array
    {
        return ['id' => $user->getId(), 'name' => trim($user->getFirstName().' '.$user->getLastName())];
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['code' => 'FORBIDDEN', 'message' => 'Droits d’audit insuffisants.'], 403);
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_INPUT', 'message' => $message], 422);
    }

    private function notFound(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => $message], 404);
    }
}
