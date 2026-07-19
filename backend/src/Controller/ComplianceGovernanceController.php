<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\RequirementMapping;
use App\Entity\SecurityControlTest;
use App\Entity\StatementOfApplicability;
use App\Entity\StatementOfApplicabilityItem;
use App\Entity\User;
use App\Repository\ActionPlanRepository;
use App\Repository\ComplianceResultRepository;
use App\Repository\FrameworkRepository;
use App\Repository\RequirementMappingRepository;
use App\Repository\RequirementRepository;
use App\Repository\RiskScenarioRepository;
use App\Repository\ScopeRepository;
use App\Repository\SecurityControlRepository;
use App\Repository\SecurityControlTestRepository;
use App\Repository\StatementOfApplicabilityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ComplianceGovernanceController
{
    public function __construct(
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
        private StatementOfApplicabilityRepository $statements,
        private SecurityControlTestRepository $controlTests,
        private RequirementMappingRepository $mappings,
        private FrameworkRepository $frameworks,
        private RequirementRepository $requirements,
        private ScopeRepository $scopes,
        private UserRepository $users,
        private SecurityControlRepository $controls,
        private RiskScenarioRepository $risks,
        private ActionPlanRepository $actions,
        private ComplianceResultRepository $results,
    ) {
    }

    #[Route('/api/statements-of-applicability', methods: ['GET'])]
    public function statements(): JsonResponse
    {
        return new JsonResponse(array_map($this->statementResponse(...), $this->statements->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/api/statements-of-applicability', methods: ['POST'])]
    public function createStatement(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }
        $data = $request->toArray();
        $actor = $this->currentUser->get();
        $framework = $this->frameworks->find((int) ($data['frameworkId'] ?? 0));
        $scope = $this->scopes->findOneVisibleTo((int) ($data['scopeId'] ?? 0), $actor);
        $owner = $this->users->findOneVisibleTo((int) ($data['ownerId'] ?? 0), $actor);
        if (null === $framework || null === $scope || null === $owner) {
            return $this->invalid('Référentiel, périmètre ou responsable invalide.');
        }
        $statement = new StatementOfApplicability($actor->getOrganization(), $framework, $scope, $owner, trim((string) ($data['title'] ?? 'Déclaration d’applicabilité')));
        foreach ($this->requirements->findBy(['framework' => $framework, 'status' => 'ACTIVE'], ['reference' => 'ASC']) as $requirement) {
            new StatementOfApplicabilityItem($statement, $requirement);
        }
        $this->entityManager->persist($statement);
        $this->entityManager->flush();

        return new JsonResponse($this->statementResponse($statement), 201);
    }

    #[Route('/api/statements-of-applicability/{id<\d+>}', methods: ['GET'])]
    public function showStatement(int $id): JsonResponse
    {
        $statement = $this->statements->findOneVisibleTo($id, $this->currentUser->get());

        return null === $statement ? $this->notFound('SoA introuvable.') : new JsonResponse($this->statementResponse($statement, true));
    }

    #[Route('/api/statements-of-applicability/{id<\d+>}/items/{itemId<\d+>}', methods: ['PUT'])]
    public function updateStatementItem(int $id, int $itemId, Request $request): JsonResponse
    {
        $statement = $this->statements->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $statement || !$this->canManage()) {
            return null === $statement ? $this->notFound('SoA introuvable.') : $this->forbidden();
        }
        $item = $statement->getItems()->filter(static fn (StatementOfApplicabilityItem $candidate): bool => $candidate->getId() === $itemId)->first();
        if (!$item instanceof StatementOfApplicabilityItem) {
            return $this->notFound('Ligne de SoA introuvable.');
        }
        $data = $request->toArray();
        $actor = $this->currentUser->get();
        $owner = empty($data['ownerId']) ? null : $this->users->findOneVisibleTo((int) $data['ownerId'], $actor);
        $controls = $this->visibleRelations((array) ($data['controlIds'] ?? []), fn (int $relationId): mixed => $this->controls->findOneVisibleTo($relationId, $actor));
        $risks = $this->visibleRelations((array) ($data['riskIds'] ?? []), fn (int $relationId): mixed => $this->risks->findOneVisibleTo($relationId, $actor));
        $actions = $this->visibleRelations((array) ($data['actionIds'] ?? []), fn (int $relationId): mixed => $this->actions->findOneVisibleTo($relationId, $actor));
        if ((!empty($data['ownerId']) && null === $owner) || null === $controls || null === $risks || null === $actions) {
            return $this->invalid('Une relation associée est invalide ou appartient à une autre organisation.');
        }
        try {
            $item->update(
                (bool) ($data['applicable'] ?? true), isset($data['justification']) ? (string) $data['justification'] : null,
                (string) ($data['implementationStatus'] ?? 'NOT_IMPLEMENTED'), $owner,
                empty($data['nextReviewAt']) ? null : new \DateTimeImmutable((string) $data['nextReviewAt']),
                $this->strings((array) ($data['evidence'] ?? [])), $controls, $risks, $actions,
            );
            $this->entityManager->flush();
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->itemResponse($item));
    }

    #[Route('/api/statements-of-applicability/{id<\d+>}/approve', methods: ['POST'])]
    public function approveStatement(int $id): JsonResponse
    {
        $statement = $this->statements->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $statement) {
            return $this->notFound('SoA introuvable.');
        }
        $actor = $this->currentUser->get();
        if (!$this->hasRole([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN]) || $statement->getOwner() === $actor) {
            return $this->forbidden('L’approbateur doit être administrateur et distinct du responsable.');
        }
        try {
            $statement->approve($actor);
            $this->entityManager->flush();
        } catch (\LogicException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->statementResponse($statement));
    }

    #[Route('/api/statements-of-applicability/{id<\d+>}/revise', methods: ['POST'])]
    public function reviseStatement(int $id): JsonResponse
    {
        $previous = $this->statements->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $previous || !$this->canManage()) {
            return null === $previous ? $this->notFound('SoA introuvable.') : $this->forbidden();
        }
        if ('APPROVED' !== $previous->getStatus()) {
            return $this->invalid('Seule une SoA approuvée peut être révisée.');
        }
        $next = new StatementOfApplicability($previous->getOrganization(), $previous->getFramework(), $previous->getScope(), $previous->getOwner(), $previous->getTitle(), $this->statements->nextVersion($previous), $previous);
        foreach ($previous->getItems() as $source) {
            $copy = new StatementOfApplicabilityItem($next, $source->getRequirement());
            $copy->update($source->isApplicable(), $source->getJustification(), $source->getImplementationStatus(), $source->getOwner(), $source->getNextReviewAt(), $source->getEvidence(), $source->getControls()->toArray(), $source->getRisks()->toArray(), $source->getActions()->toArray());
        }
        $previous->supersede();
        $this->entityManager->persist($next);
        $this->entityManager->flush();

        return new JsonResponse($this->statementResponse($next), 201);
    }

    #[Route('/api/statements-of-applicability/{id<\d+>}/export', methods: ['GET'])]
    public function exportStatement(int $id): Response
    {
        $statement = $this->statements->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $statement) {
            return $this->notFound('SoA introuvable.');
        }
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Référence', 'Exigence', 'Applicable', 'Justification', 'Mise en œuvre', 'Responsable', 'Contrôles', 'Risques', 'Actions', 'Preuves', 'Prochaine revue'], ';');
        foreach ($statement->getItems() as $item) {
            fputcsv($stream, [$item->getRequirement()->getReference(), $item->getRequirement()->getTitle(), $item->isApplicable() ? 'Oui' : 'Non', $item->getJustification(), $item->getImplementationStatus(), null === $item->getOwner() ? null : $this->userName($item->getOwner()), implode(', ', array_map(static fn ($control): string => $control->getName(), $item->getControls()->toArray())), implode(', ', array_map(static fn ($risk): string => $risk->getTitle(), $item->getRisks()->toArray())), implode(', ', array_map(static fn ($action): string => $action->getTitle(), $item->getActions()->toArray())), implode(', ', $item->getEvidence()), $item->getNextReviewAt()?->format('Y-m-d')], ';');
        }
        rewind($stream);
        $content = "\xEF\xBB\xBF".(string) stream_get_contents($stream);
        fclose($stream);

        return new Response($content, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => sprintf('attachment; filename="soa-v%d.csv"', $statement->getVersionNumber())]);
    }

    #[Route('/api/control-tests', methods: ['GET'])]
    public function controlTests(): JsonResponse
    {
        return new JsonResponse(array_map($this->controlTestResponse(...), $this->controlTests->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/api/control-tests', methods: ['POST'])]
    public function createControlTest(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }
        $data = $request->toArray();
        $actor = $this->currentUser->get();
        $control = $this->controls->findOneVisibleTo((int) ($data['controlId'] ?? 0), $actor);
        $tester = $this->users->findOneVisibleTo((int) ($data['testerId'] ?? 0), $actor);
        if (null === $control || null === $tester) {
            return $this->invalid('Contrôle ou testeur invalide.');
        }
        try {
            $test = new SecurityControlTest($control, $tester, (string) ($data['type'] ?? ''), (string) ($data['frequency'] ?? ''), (string) ($data['procedure'] ?? ''), new \DateTimeImmutable((string) ($data['performedAt'] ?? 'now')), new \DateTimeImmutable((string) ($data['nextReviewAt'] ?? 'now')));
            $test->conclude((string) ($data['result'] ?? 'NOT_TESTED'), isset($data['sampleDescription']) ? (string) $data['sampleDescription'] : null, isset($data['sampleSize']) ? (int) $data['sampleSize'] : null, isset($data['conclusion']) ? (string) $data['conclusion'] : null, $this->strings((array) ($data['evidence'] ?? [])));
            $this->entityManager->persist($test);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->controlTestResponse($test), 201);
    }

    #[Route('/api/requirement-mappings', methods: ['GET'])]
    public function requirementMappings(): JsonResponse
    {
        return new JsonResponse(array_map($this->mappingResponse(...), $this->mappings->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/api/requirement-mappings', methods: ['POST'])]
    public function createRequirementMapping(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }
        $data = $request->toArray();
        $source = $this->requirements->find((int) ($data['sourceRequirementId'] ?? 0));
        $target = $this->requirements->find((int) ($data['targetRequirementId'] ?? 0));
        if (null === $source || null === $target) {
            return $this->invalid('Exigence source ou cible invalide.');
        }
        try {
            $mapping = new RequirementMapping($this->currentUser->get()->getOrganization(), $source, $target, (int) ($data['coveragePercent'] ?? 100), (bool) ($data['inheritEvidence'] ?? true), $this->currentUser->get(), isset($data['rationale']) ? (string) $data['rationale'] : null);
            $this->entityManager->persist($mapping);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->mappingResponse($mapping), 201);
    }

    #[Route('/api/requirement-mappings/{id<\d+>}', methods: ['DELETE'])]
    public function deleteRequirementMapping(int $id): JsonResponse
    {
        $mapping = $this->mappings->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $mapping || !$this->canManage()) {
            return null === $mapping ? $this->notFound('Correspondance introuvable.') : $this->forbidden();
        }
        $this->entityManager->remove($mapping);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    #[Route('/api/compliance-results/{id<\d+>}/inherited-evidence', methods: ['GET'])]
    public function inheritedEvidence(int $id): JsonResponse
    {
        $result = $this->results->find($id);
        $actor = $this->currentUser->get();
        if (null === $result || $result->getAssessment()->getOrganization() !== $actor->getOrganization()) {
            return $this->notFound('Résultat de conformité introuvable.');
        }
        $items = [];
        foreach ($this->mappings->findBy(['organization' => $actor->getOrganization(), 'targetRequirement' => $result->getRequirement(), 'inheritEvidence' => true]) as $mapping) {
            foreach ($this->results->findBy(['requirement' => $mapping->getSourceRequirement()]) as $source) {
                if ($source->getAssessment()->getOrganization() === $actor->getOrganization() && [] !== $source->getEvidence()) {
                    $items[] = ['mappingId' => $mapping->getId(), 'coveragePercent' => $mapping->getCoveragePercent(), 'sourceRequirement' => $source->getRequirement()->getReference(), 'sourceFramework' => $source->getRequirement()->getFramework()->getName(), 'assessmentId' => $source->getAssessment()->getId(), 'evidence' => $source->getEvidence()];
                }
            }
        }

        return new JsonResponse($items);
    }

    /** @return array<string, mixed> */
    private function statementResponse(StatementOfApplicability $statement, bool $withItems = false): array
    {
        $response = ['id' => $statement->getId(), 'title' => $statement->getTitle(), 'version' => $statement->getVersionNumber(), 'status' => $statement->getStatus(), 'framework' => ['id' => $statement->getFramework()->getId(), 'name' => $statement->getFramework()->getName(), 'version' => $statement->getFramework()->getVersion()], 'scope' => ['id' => $statement->getScope()->getId(), 'name' => $statement->getScope()->getName()], 'owner' => ['id' => $statement->getOwner()->getId(), 'name' => $this->userName($statement->getOwner())], 'approvedBy' => null === $statement->getApprovedBy() ? null : ['id' => $statement->getApprovedBy()->getId(), 'name' => $this->userName($statement->getApprovedBy())], 'approvedAt' => $statement->getApprovedAt()?->format(DATE_ATOM), 'itemCount' => $statement->getItems()->count(), 'createdAt' => $statement->getCreatedAt()->format(DATE_ATOM)];
        if ($withItems) {
            $items = $statement->getItems()->toArray();
            usort($items, static fn (StatementOfApplicabilityItem $a, StatementOfApplicabilityItem $b): int => strcmp($a->getRequirement()->getReference(), $b->getRequirement()->getReference()));
            $response['items'] = array_map($this->itemResponse(...), $items);
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function itemResponse(StatementOfApplicabilityItem $item): array
    {
        return ['id' => $item->getId(), 'requirement' => ['id' => $item->getRequirement()->getId(), 'reference' => $item->getRequirement()->getReference(), 'title' => $item->getRequirement()->getTitle()], 'applicable' => $item->isApplicable(), 'justification' => $item->getJustification(), 'implementationStatus' => $item->getImplementationStatus(), 'owner' => null === $item->getOwner() ? null : ['id' => $item->getOwner()->getId(), 'name' => $this->userName($item->getOwner())], 'nextReviewAt' => $item->getNextReviewAt()?->format('Y-m-d'), 'evidence' => $item->getEvidence(), 'controls' => array_map(static fn ($control): array => ['id' => $control->getId(), 'name' => $control->getName()], $item->getControls()->toArray()), 'risks' => array_map(static fn ($risk): array => ['id' => $risk->getId(), 'title' => $risk->getTitle()], $item->getRisks()->toArray()), 'actions' => array_map(static fn ($action): array => ['id' => $action->getId(), 'title' => $action->getTitle()], $item->getActions()->toArray())];
    }

    /** @return array<string, mixed> */
    private function controlTestResponse(SecurityControlTest $test): array
    {
        return ['id' => $test->getId(), 'control' => ['id' => $test->getControl()->getId(), 'name' => $test->getControl()->getName()], 'tester' => ['id' => $test->getTester()->getId(), 'name' => $this->userName($test->getTester())], 'type' => $test->getType(), 'frequency' => $test->getFrequency(), 'result' => $test->getResult(), 'procedure' => $test->getProcedure(), 'sampleDescription' => $test->getSampleDescription(), 'sampleSize' => $test->getSampleSize(), 'conclusion' => $test->getConclusion(), 'evidence' => $test->getEvidence(), 'performedAt' => $test->getPerformedAt()->format('Y-m-d'), 'nextReviewAt' => $test->getNextReviewAt()->format('Y-m-d')];
    }

    /** @return array<string, mixed> */
    private function mappingResponse(RequirementMapping $mapping): array
    {
        return ['id' => $mapping->getId(), 'source' => ['id' => $mapping->getSourceRequirement()->getId(), 'reference' => $mapping->getSourceRequirement()->getReference(), 'framework' => $mapping->getSourceRequirement()->getFramework()->getName()], 'target' => ['id' => $mapping->getTargetRequirement()->getId(), 'reference' => $mapping->getTargetRequirement()->getReference(), 'framework' => $mapping->getTargetRequirement()->getFramework()->getName()], 'coveragePercent' => $mapping->getCoveragePercent(), 'inheritEvidence' => $mapping->doesInheritEvidence(), 'rationale' => $mapping->getRationale()];
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<object>|null
     */
    private function visibleRelations(array $ids, callable $finder): ?array
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
        return array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $values), static fn (string $value): bool => '' !== $value));
    }

    private function canManage(): bool
    {
        return $this->hasRole([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_RISK_MANAGER, User::ROLE_AUDITOR]);
    }

    private function userName(User $user): string
    {
        return trim($user->getFirstName().' '.$user->getLastName());
    }

    /** @param list<string> $roles */
    private function hasRole(array $roles): bool
    {
        return [] !== array_intersect($roles, $this->currentUser->get()->getRoles());
    }

    private function forbidden(string $message = 'Droits insuffisants.'): JsonResponse
    {
        return new JsonResponse(['code' => 'FORBIDDEN', 'message' => $message], 403);
    }

    private function notFound(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => $message], 404);
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_INPUT', 'message' => $message], 422);
    }
}
