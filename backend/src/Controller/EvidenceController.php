<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\ApiPagination;
use App\Api\Dto\EvidenceInput;
use App\Api\JsonInputMapper;
use App\Api\ApiResponseFactory;
use App\Application\CurrentUser;
use App\Entity\ActionPlan;
use App\Entity\ComplianceResult;
use App\Entity\Evidence;
use App\Entity\Requirement;
use App\Entity\SecurityControl;
use App\Entity\User;
use App\Repository\EvidenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/evidence')]
final readonly class EvidenceController
{
    public function __construct(private CurrentUser $currentUser, private EvidenceRepository $evidence, private EntityManagerInterface $entityManager, private JsonInputMapper $mapper, private ApiResponseFactory $responses) {}

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $actor = $this->currentUser->get(); $pagination = ApiPagination::fromRequest($request); $status = strtoupper(trim((string) $request->query->get('status', ''))) ?: null;
        if (null !== $status && !in_array($status, Evidence::STATUSES, true)) return $this->error('INVALID_STATUS', 422);
        $items = $this->evidence->findVisibleTo($actor, $pagination['page'], $pagination['limit'], $status);
        return new JsonResponse(ApiPagination::response(array_map($this->serialize(...), $items), $pagination['page'], $pagination['limit'], $this->evidence->countVisibleTo($actor, $status)));
    }

    #[Route('', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        [$input, $violations] = $this->mapper->map($request, EvidenceInput::class); if (count($violations) > 0) return $this->responses->validationError($violations);
        try { $item = $this->make($input); $this->entityManager->persist($item); $this->entityManager->flush(); }
        catch (\InvalidArgumentException|\LogicException $e) { return $this->error($e->getMessage(), 422); }
        return new JsonResponse($this->serialize($item), 201);
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse { $item = $this->evidence->findOneVisibleTo($id, $this->currentUser->get()); return null === $item ? $this->error('Preuve introuvable.', 404) : new JsonResponse($this->serialize($item)); }

    #[Route('/coverage/requirements/{requirementId<\d+>}', methods: ['GET'])]
    public function coverage(int $requirementId): JsonResponse
    {
        $actor = $this->currentUser->get(); $requirement = $this->entityManager->getRepository(Requirement::class)->find($requirementId); if (!$requirement instanceof Requirement) return $this->error('Exigence introuvable.', 404);
        $evidence = $this->evidence->findForRequirement($requirementId, $actor); $results = array_values(array_filter($this->entityManager->getRepository(ComplianceResult::class)->findBy(['requirement' => $requirement]), fn (ComplianceResult $result): bool => $result->getAssessment()->getOrganization() === $actor->getOrganization()));
        $controls = []; $actions = []; foreach ($evidence as $item) { foreach ($item->getControls() as $control) $controls[$control->getId()] = ['id' => $control->getId(), 'name' => $control->getName()]; foreach ($item->getActions() as $action) $actions[$action->getId()] = ['id' => $action->getId(), 'title' => $action->getTitle(), 'status' => $action->getStatus()]; } foreach ($results as $result) foreach ($result->getActions() as $action) $actions[$action->getId()] = ['id' => $action->getId(), 'title' => $action->getTitle(), 'status' => $action->getStatus()];
        $approvedEvidence = count(array_filter($evidence, static fn (Evidence $item): bool => 'APPROVED' === $item->getStatus() && (null === $item->getExpiresAt() || $item->getExpiresAt() >= new \DateTimeImmutable('today'))));
        return new JsonResponse(['requirement' => ['id' => $requirement->getId(), 'reference' => $requirement->getReference(), 'title' => $requirement->getTitle(), 'framework' => $requirement->getFramework()->getName().' '.$requirement->getFramework()->getVersion()], 'controls' => array_values($controls), 'evidence' => array_map($this->serialize(...), $evidence), 'results' => array_map(static fn (ComplianceResult $result): array => ['id' => $result->getId(), 'status' => $result->getComplianceStatus(), 'maturity' => $result->getMaturityLevel(), 'assessmentId' => $result->getAssessment()->getId()], $results), 'actions' => array_values($actions), 'explanation' => ['approvedEvidence' => $approvedEvidence, 'expiredOrUnapprovedEvidence' => count($evidence) - $approvedEvidence, 'assessmentStates' => array_count_values(array_map(static fn (ComplianceResult $result): string => $result->getComplianceStatus(), $results)), 'rule' => 'Une preuve approuvée et non expirée documente la couverture ; elle ne rend jamais automatiquement le résultat conforme.']]);
    }

    #[Route('/{id<\d+>}/submit', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function submit(int $id): JsonResponse
    {
        $item = $this->evidence->findOneVisibleTo($id, $this->currentUser->get()); if (null === $item) return $this->error('Preuve introuvable.', 404);
        try { $item->submit(); $this->entityManager->flush(); } catch (\LogicException $e) { return $this->error($e->getMessage(), 422); }
        return new JsonResponse($this->serialize($item));
    }

    #[Route('/{id<\d+>}/approve', methods: ['POST'])] #[IsGranted(User::ROLE_ADMIN)]
    public function approve(int $id): JsonResponse
    {
        $item = $this->evidence->findOneVisibleTo($id, $this->currentUser->get()); if (null === $item) return $this->error('Preuve introuvable.', 404);
        try { $item->approve($this->currentUser->get()); $this->entityManager->flush(); } catch (\LogicException $e) { return $this->error($e->getMessage(), 422); }
        return new JsonResponse($this->serialize($item));
    }

    #[Route('/{id<\d+>}/revise', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function revise(int $id, Request $request): JsonResponse
    {
        $previous = $this->evidence->findOneVisibleTo($id, $this->currentUser->get()); if (null === $previous) return $this->error('Preuve introuvable.', 404);
        [$input, $violations] = $this->mapper->map($request, EvidenceInput::class); if (count($violations) > 0) return $this->responses->validationError($violations);
        try { $next = $this->make($input, $previous); $previous->supersede(); $this->entityManager->persist($next); $this->entityManager->flush(); }
        catch (\InvalidArgumentException|\LogicException $e) { return $this->error($e->getMessage(), 422); }
        return new JsonResponse($this->serialize($next), 201);
    }

    private function make(EvidenceInput $data, ?Evidence $previous = null): Evidence
    {
        $actor = $this->currentUser->get();
        $item = new Evidence($actor->getOrganization(), $actor, (string) $data->title, $data->kind, $data->classification, (string) $data->sourceReference, $data->sha256, empty($data->validFrom) ? null : new \DateTimeImmutable($data->validFrom), empty($data->expiresAt) ? null : new \DateTimeImmutable($data->expiresAt), $previous);
        $requirements = $this->relations(Requirement::class, $data->requirementIds, static fn (Requirement $value): bool => 'ACTIVE' === $value->getStatus());
        $controls = $this->relations(SecurityControl::class, $data->controlIds, fn (SecurityControl $value): bool => $value->getOrganization() === $actor->getOrganization());
        $results = $this->relations(ComplianceResult::class, $data->resultIds, fn (ComplianceResult $value): bool => $value->getAssessment()->getOrganization() === $actor->getOrganization());
        $actions = $this->relations(ActionPlan::class, $data->actionIds, fn (ActionPlan $value): bool => $value->getOrganization() === $actor->getOrganization());
        $item->link($requirements, $controls, $results, $actions); return $item;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param list<mixed> $ids
     * @param callable(T): bool $visible
     * @return list<T>
     */
    private function relations(string $class, array $ids, callable $visible): array
    {
        $items = []; foreach (array_values(array_unique(array_map('intval', $ids))) as $id) { $item = $this->entityManager->getRepository($class)->find($id); if (null === $item || !$visible($item)) throw new \InvalidArgumentException('Une relation de preuve est inexistante ou hors tenant.'); $items[] = $item; } return $items;
    }

    /** @return array<string, mixed> */
    private function serialize(Evidence $item): array
    {
        return ['id' => $item->getId(), 'title' => $item->getTitle(), 'kind' => $item->getKind(), 'classification' => $item->getClassification(), 'sourceReference' => $item->getSourceReference(), 'sha256' => $item->getSha256(), 'version' => $item->getVersion(), 'status' => $item->getStatus(), 'validFrom' => $item->getValidFrom()?->format('Y-m-d'), 'expiresAt' => $item->getExpiresAt()?->format('Y-m-d'), 'owner' => ['id' => $item->getOwner()->getId(), 'name' => trim($item->getOwner()->getFirstName().' '.$item->getOwner()->getLastName())], 'approvedBy' => null === $item->getApprovedBy() ? null : ['id' => $item->getApprovedBy()->getId(), 'name' => trim($item->getApprovedBy()->getFirstName().' '.$item->getApprovedBy()->getLastName())], 'approvedAt' => $item->getApprovedAt()?->format(DATE_ATOM), 'supersedesId' => $item->getSupersedes()?->getId(), 'requirementIds' => array_map(static fn (Requirement $x): ?int => $x->getId(), $item->getRequirements()->toArray()), 'controlIds' => array_map(static fn (SecurityControl $x): ?int => $x->getId(), $item->getControls()->toArray()), 'resultIds' => array_map(static fn (ComplianceResult $x): ?int => $x->getId(), $item->getResults()->toArray()), 'actionIds' => array_map(static fn (ActionPlan $x): ?int => $x->getId(), $item->getActions()->toArray()), 'createdAt' => $item->getCreatedAt()->format(DATE_ATOM), 'updatedAt' => $item->getUpdatedAt()->format(DATE_ATOM)];
    }
    private function error(string $message, int $status): JsonResponse { return new JsonResponse(['code' => $status === 404 ? 'NOT_FOUND' : 'VALIDATION_ERROR', 'message' => $message], $status); }
}
