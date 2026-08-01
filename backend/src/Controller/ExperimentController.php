<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\AssistantProposal;
use App\Entity\ComplianceAssessment;
use App\Entity\KnowledgeLibraryItem;
use App\Entity\OperationalRecord;
use App\Entity\Requirement;
use App\Entity\RiskScenario;
use App\Entity\SecurityControl;
use App\Entity\User;
use App\Repository\AssistantProposalRepository;
use App\Repository\KnowledgeLibraryItemRepository;
use App\Repository\OperationalRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/experiments')]
final readonly class ExperimentController
{
    public function __construct(
        private CurrentUser $currentUser,
        private AssistantProposalRepository $proposals,
        private KnowledgeLibraryItemRepository $library,
        private OperationalRecordRepository $records,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/settings', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        return new JsonResponse($this->settingsData());
    }

    #[Route('/settings', methods: ['PUT'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function updateSettings(Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $data = $request->toArray();
        $details = ['assistantEnabled' => (bool) ($data['assistantEnabled'] ?? false), 'allowedKinds' => array_values(array_intersect(AssistantProposal::KINDS, array_map('strval', (array) ($data['allowedKinds'] ?? AssistantProposal::KINDS)))), 'updatedBy' => $actor->getId(), 'updatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)];
        $setting = $this->settingsRecord();
        if (null === $setting) {
            $setting = new OperationalRecord($actor->getOrganization(), 'P3_SETTINGS', 'Assistant expérimental', $details);
            $this->entityManager->persist($setting);
        }
        $setting->update($setting->getTitle(), 'ACTIVE', $details, $actor, null);
        $this->entityManager->flush();

        return new JsonResponse($details);
    }

    #[Route('/assistant/proposals', methods: ['GET'])]
    public function proposals(Request $request): JsonResponse
    {
        $organization = $this->currentUser->get()->getOrganization();
        $limit = min(100, max(1, $request->query->getInt('limit', 25)));
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->proposals->countForOrganization($organization);

        return new JsonResponse(['items' => array_map($this->proposalResponse(...), $this->proposals->findForOrganization($organization, $limit, $page)), 'page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)]);
    }

    #[Route('/assistant/proposals', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function propose(Request $request): JsonResponse
    {
        $settings = $this->settingsData();
        $data = $request->toArray();
        $kind = strtoupper((string) ($data['kind'] ?? ''));
        if (true !== $settings['assistantEnabled'] || !in_array($kind, $settings['allowedKinds'], true)) {
            return $this->error('ASSISTANT_DISABLED', 'Assistant désactivé ou usage non autorisé.', 403);
        }
        try {
            [$proposal, $sources] = $this->generate($kind, (array) ($data['context'] ?? []));
            $item = new AssistantProposal($this->currentUser->get()->getOrganization(), $this->currentUser->get(), $kind, (array) ($data['context'] ?? []), $proposal, $sources);
            $this->entityManager->persist($item);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_REQUEST', $e->getMessage(), 422);
        }

        return new JsonResponse($this->proposalResponse($item), 201);
    }

    #[Route('/assistant/proposals/{id<\d+>}/validate', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function validateProposal(int $id, Request $request): JsonResponse
    {
        $proposal = $this->proposals->find($id);
        if (!$proposal instanceof AssistantProposal || $proposal->getOrganization() !== $this->currentUser->get()->getOrganization()) {
            return $this->error('NOT_FOUND', 'Proposition introuvable.', 404);
        }
        $data = $request->toArray();
        try {
            $proposal->validate($this->currentUser->get(), strtoupper((string) ($data['decision'] ?? '')), (string) ($data['comment'] ?? ''));
            $this->entityManager->flush();
        } catch (\InvalidArgumentException|\LogicException $e) {
            return $this->error('INVALID_VALIDATION', $e->getMessage(), 422);
        }

        return new JsonResponse($this->proposalResponse($proposal));
    }

    #[Route('/assistant/evaluation', methods: ['GET'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function evaluation(): JsonResponse
    {
        $items = $this->proposals->findForOrganization($this->currentUser->get()->getOrganization(), 200);
        $validated = array_filter($items, static fn (AssistantProposal $item): bool => 'PENDING' !== $item->getStatus());
        $rejected = array_filter($items, static fn (AssistantProposal $item): bool => 'REJECTED' === $item->getStatus());
        $unsupported = array_filter($items, static fn (AssistantProposal $item): bool => $item->getSourceCoverage() < 1.0);

        return new JsonResponse(['total' => count($items), 'validated' => count($validated), 'rejected' => count($rejected), 'humanRejectionRate' => [] === $validated ? 0 : round(count($rejected) / count($validated), 4), 'unsupportedProposalRate' => [] === $items ? 0 : round(count($unsupported) / count($items), 4), 'automaticDecisions' => 0]);
    }

    #[Route('/library', methods: ['GET'])]
    public function library(Request $request): JsonResponse
    {
        $kind = $request->query->get('kind');
        $status = $request->query->get('status');
        $limit = min(100, max(1, $request->query->getInt('limit', 25)));
        $items = $this->library->search($this->currentUser->get()->getOrganization(), is_string($kind) ? $kind : null, is_string($status) ? $status : null, $request->query->getInt('page', 1), $limit);

        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->library->countVisible($this->currentUser->get()->getOrganization(), is_string($kind) ? $kind : null, is_string($status) ? $status : null);

        return new JsonResponse(['items' => array_map($this->libraryResponse(...), $items), 'page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)]);
    }

    #[Route('/library', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function createLibraryItem(Request $request): JsonResponse
    {
        return $this->saveLibrary(null, $request->toArray());
    }

    #[Route('/library/import', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function importLibrary(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $rows = array_values((array) ($data['items'] ?? []));
        $dryRun = true !== ($data['commit'] ?? false);
        if (1 !== (int) ($data['schemaVersion'] ?? 0) || [] === $rows || count($rows) > 100) {
            return $this->error('INVALID_IMPORT', 'Le schéma, le nombre de lignes ou le lot est invalide (maximum 100).', 422);
        }
        $actor = $this->currentUser->get();
        $items = [];
        $results = [];
        foreach ($rows as $index => $row) {
            try {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('Ligne non structurée.');
                }
                $key = strtolower(trim((string) ($row['key'] ?? '')));
                if (null !== $this->library->findOneBy(['organization' => $actor->getOrganization(), 'key' => $key, 'version' => 1])) {
                    throw new \InvalidArgumentException('La clé existe déjà dans ce tenant.');
                }
                $dependencies = (array) ($row['dependencies'] ?? []);
                $this->assertDependencies($dependencies);
                $item = new KnowledgeLibraryItem($actor->getOrganization(), $actor, $key, strtoupper((string) ($row['kind'] ?? '')), (string) ($row['title'] ?? ''), 1, (array) ($row['content'] ?? []), $dependencies, isset($row['source']) ? (string) $row['source'] : null, isset($row['license']) ? (string) $row['license'] : null);
                $items[] = $item;
                $results[] = ['row' => $index + 1, 'key' => $key, 'valid' => true];
            } catch (\InvalidArgumentException $e) {
                $results[] = ['row' => $index + 1, 'key' => is_array($row) ? (string) ($row['key'] ?? '') : '', 'valid' => false, 'error' => $e->getMessage()];
            }
        }
        if (count($items) !== count($rows)) {
            return new JsonResponse(['dryRun' => $dryRun, 'imported' => 0, 'results' => $results], 422);
        }
        if ($dryRun) {
            return new JsonResponse(['dryRun' => true, 'imported' => 0, 'results' => $results]);
        }
        try {
            foreach ($items as $item) {
                $this->entityManager->persist($item);
            }
            $this->entityManager->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            return $this->error('IMPORT_CONFLICT', $e->getMessage(), 409);
        }

        return new JsonResponse(['dryRun' => false, 'imported' => count($items), 'items' => array_map($this->libraryResponse(...), $items)], 201);
    }

    #[Route('/library/{id<\d+>}/revisions', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function reviseLibraryItem(int $id, Request $request): JsonResponse
    {
        $previous = $this->library->findVisible($id, $this->currentUser->get()->getOrganization());
        if (null === $previous || !in_array($previous->getStatus(), ['APPROVED', 'RETIRED'], true)) {
            return $this->error('NOT_FOUND', 'Version approuvée ou retirée introuvable.', 404);
        }

        return $this->saveLibrary($previous, $request->toArray());
    }

    #[Route('/library/{id<\d+>}/submit', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function submitLibraryItem(int $id): JsonResponse
    {
        return $this->transitionLibrary($id, 'submit');
    }

    #[Route('/library/{id<\d+>}/approve', methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function approveLibraryItem(int $id): JsonResponse
    {
        return $this->transitionLibrary($id, 'approve');
    }

    #[Route('/library/{id<\d+>}/retire', methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function retireLibraryItem(int $id): JsonResponse
    {
        return $this->transitionLibrary($id, 'retire');
    }

    #[Route('/library/export', methods: ['GET'])]
    public function exportLibrary(): JsonResponse
    {
        $items = $this->library->search($this->currentUser->get()->getOrganization(), null, 'APPROVED', 1, 100);

        return new JsonResponse(['schemaVersion' => 1, 'exportedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'items' => array_map($this->libraryResponse(...), $items)], 200, ['Content-Disposition' => 'attachment; filename="riskpilot-library.json"']);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{array<string, mixed>, list<array{type: string, id: int, label: string}>}
     */
    private function generate(string $kind, array $context): array
    {
        $organization = $this->currentUser->get()->getOrganization();
        if ('MAPPING_SUGGESTION' === $kind) {
            $requirement = $this->entityManager->getRepository(Requirement::class)->find((int) ($context['requirementId'] ?? 0));
            if (!$requirement instanceof Requirement) {
                throw new \InvalidArgumentException('Exigence introuvable.');
            }
            $controls = $this->entityManager->getRepository(SecurityControl::class)->findBy(['organization' => $organization]);
            $tokens = array_filter(preg_split('/\W+/u', mb_strtolower($requirement->getTitle())) ?: [], static fn (string $token): bool => mb_strlen($token) > 3);
            $matches = array_values(array_filter($controls, static fn (SecurityControl $control): bool => [] !== array_filter($tokens, static fn (string $token): bool => str_contains(mb_strtolower($control->getName().' '.$control->getCategory()), $token))));
            $sources = [['type' => 'REQUIREMENT', 'id' => (int) $requirement->getId(), 'label' => $requirement->getReference().' '.$requirement->getTitle()]];
            foreach ($matches as $control) {
                $sources[] = ['type' => 'CONTROL', 'id' => (int) $control->getId(), 'label' => $control->getName()];
            }

            return [['requirementId' => $requirement->getId(), 'suggestedControlIds' => array_map(static fn (SecurityControl $control): ?int => $control->getId(), $matches), 'rationale' => 'Correspondance lexicale à valider humainement.'], $sources];
        }
        if ('GAP_SUMMARY' === $kind) {
            $assessments = $this->entityManager->getRepository(ComplianceAssessment::class)->findBy(['organization' => $organization]);
            $sources = array_map(static fn (ComplianceAssessment $item): array => ['type' => 'ASSESSMENT', 'id' => (int) $item->getId(), 'label' => $item->getFramework()->getName()], $assessments);

            return [['assessmentCount' => count($assessments), 'summary' => [] === $assessments ? 'Aucune évaluation disponible.' : sprintf('%d évaluation(s) tenant-scoped à examiner ; aucun score n’est modifié.', count($assessments))], $sources];
        }
        if ('REPORT_DRAFT' === $kind) {
            $risks = $this->entityManager->getRepository(RiskScenario::class)->findBy(['organization' => $organization]);
            $sources = array_map(static fn (RiskScenario $item): array => ['type' => 'RISK', 'id' => (int) $item->getId(), 'label' => $item->getTitle()], array_slice($risks, 0, 20));

            return [['title' => (string) ($context['title'] ?? 'Brouillon de rapport'), 'paragraphs' => [sprintf('%d risque(s) sont visibles dans le tenant.', count($risks)), 'Ce brouillon doit être vérifié et approuvé avant diffusion.']], $sources];
        }
        if ('QUESTION_SUGGESTIONS' === $kind) {
            $controls = $this->entityManager->getRepository(SecurityControl::class)->findBy(['organization' => $organization]);
            $sources = array_map(static fn (SecurityControl $item): array => ['type' => 'CONTROL', 'id' => (int) $item->getId(), 'label' => $item->getName()], array_slice($controls, 0, 20));

            return [['questions' => array_map(static fn (SecurityControl $item): array => ['label' => 'Comment démontrez-vous l’efficacité du contrôle « '.$item->getName().' » ?', 'controlId' => $item->getId()], array_slice($controls, 0, 10)), 'warning' => 'Questions proposées, jamais envoyées automatiquement.'], $sources];
        }

        throw new \InvalidArgumentException('Type de proposition inconnu.');
    }

    /** @param array<string, mixed> $data */
    private function saveLibrary(?KnowledgeLibraryItem $previous, array $data): JsonResponse
    {
        $actor = $this->currentUser->get();
        try {
            $dependencies = (array) ($data['dependencies'] ?? []);
            $this->assertDependencies($dependencies);
            $item = new KnowledgeLibraryItem($actor->getOrganization(), $actor, null === $previous ? strtolower(trim((string) ($data['key'] ?? ''))) : $previous->getKey(), null === $previous ? strtoupper((string) ($data['kind'] ?? '')) : $previous->getKind(), (string) ($data['title'] ?? $previous?->getTitle() ?? ''), null === $previous ? 1 : $previous->getVersion() + 1, (array) ($data['content'] ?? []), $dependencies, isset($data['source']) ? (string) $data['source'] : $previous?->getSource(), isset($data['license']) ? (string) $data['license'] : $previous?->getLicense(), $previous);
            $this->entityManager->persist($item);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException|\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            return $this->error('INVALID_LIBRARY_ITEM', $e->getMessage(), 422);
        }

        return new JsonResponse($this->libraryResponse($item), 201);
    }

    /** @param array<array-key, mixed> $dependencies */
    private function assertDependencies(array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            if (!is_array($dependency) || '' === trim((string) ($dependency['key'] ?? '')) || (int) ($dependency['minVersion'] ?? 0) < 1 || !$this->library->hasApprovedDependency($this->currentUser->get()->getOrganization(), (string) $dependency['key'], (int) $dependency['minVersion'])) {
                throw new \InvalidArgumentException('Une dépendance est invalide, étrangère ou non approuvée.');
            }
        }
    }

    private function transitionLibrary(int $id, string $transition): JsonResponse
    {
        $item = $this->library->findVisible($id, $this->currentUser->get()->getOrganization());
        if (null === $item) {
            return $this->error('NOT_FOUND', 'Élément introuvable.', 404);
        }
        try {
            match ($transition) {
                'submit' => $item->submit(), 'approve' => $item->approve($this->currentUser->get()), 'retire' => $item->retire(), default => throw new \LogicException('Transition inconnue.'),
            };
            $this->entityManager->flush();
        } catch (\LogicException $e) {
            return $this->error('INVALID_TRANSITION', $e->getMessage(), 422);
        }

        return new JsonResponse($this->libraryResponse($item));
    }

    /** @return array<string, mixed> */
    private function settingsData(): array
    {
        $details = $this->settingsRecord()?->getDetails() ?? [];

        return ['assistantEnabled' => (bool) ($details['assistantEnabled'] ?? false), 'allowedKinds' => array_values(array_intersect(AssistantProposal::KINDS, (array) ($details['allowedKinds'] ?? AssistantProposal::KINDS))), 'automaticDecisions' => false];
    }

    private function settingsRecord(): ?OperationalRecord
    {
        return $this->records->findForOrganization($this->currentUser->get()->getOrganization(), 'P3_SETTINGS')[0] ?? null;
    }

    /** @return array<string, mixed> */
    private function proposalResponse(AssistantProposal $item): array
    {
        return ['id' => $item->getId(), 'kind' => $item->getKind(), 'request' => $item->getRequestData(), 'proposal' => $item->getProposal(), 'sources' => $item->getSources(), 'sourceCoverage' => $item->getSourceCoverage(), 'status' => $item->getStatus(), 'requestedBy' => $item->getRequestedBy()->getId(), 'validatedBy' => $item->getValidatedBy()?->getId(), 'validationComment' => $item->getValidationComment(), 'createdAt' => $item->getCreatedAt()->format(DATE_ATOM), 'validatedAt' => $item->getValidatedAt()?->format(DATE_ATOM), 'appliedAutomatically' => false];
    }

    /** @return array<string, mixed> */
    private function libraryResponse(KnowledgeLibraryItem $item): array
    {
        return ['id' => $item->getId(), 'key' => $item->getKey(), 'kind' => $item->getKind(), 'title' => $item->getTitle(), 'version' => $item->getVersion(), 'status' => $item->getStatus(), 'content' => $item->getContent(), 'dependencies' => $item->getDependencies(), 'source' => $item->getSource(), 'license' => $item->getLicense(), 'ownerId' => $item->getOwner()->getId(), 'approvedById' => $item->getApprovedBy()?->getId(), 'approvedAt' => $item->getApprovedAt()?->format(DATE_ATOM), 'supersedesId' => $item->getSupersedes()?->getId(), 'createdAt' => $item->getCreatedAt()->format(DATE_ATOM)];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'message' => $message], $status);
    }
}
