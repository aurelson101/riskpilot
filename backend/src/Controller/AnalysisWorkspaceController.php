<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\AnalysisArtifact;
use App\Entity\RiskAnalysis;
use App\Entity\RiskScenario;
use App\Entity\Scope;
use App\Entity\User;
use App\Repository\AnalysisArtifactRepository;
use App\Repository\RiskAnalysisRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/analysis-workspace')] final readonly class AnalysisWorkspaceController
{
    public function __construct(private CurrentUser $currentUser, private RiskAnalysisRepository $analyses, private AnalysisArtifactRepository $artifacts, private EntityManagerInterface $em)
    {
    }

    #[Route('/analyses', methods: ['GET'])]
    public function index(Request $r): JsonResponse
    {
        $u = $this->currentUser->get();
        $limit = min(100, max(1, $r->query->getInt('limit', 25)));
        $page = max(1, $r->query->getInt('page', 1));
        $all = array_values(array_filter($this->analyses->search($u->getOrganization(), $page, $limit), fn (RiskAnalysis $a) => $this->access($a, $u)));

        return new JsonResponse(['items' => array_map($this->analysis(...), $all), 'page' => $page, 'limit' => $limit]);
    }

    #[Route('/analyses', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $r): JsonResponse
    {
        try {
            $d = $r->toArray();
            $u = $this->currentUser->get();
            $a = new RiskAnalysis($u->getOrganization(), $u, (string) ($d['key'] ?? ''), 1, strtoupper((string) ($d['method'] ?? '')), (string) ($d['title'] ?? ''), $d);
            $a->configure($this->scope($d), $d);
            $this->em->persist($a);
            $this->em->flush();

            return new JsonResponse($this->analysis($a), 201);
        } catch (\Throwable $e) {
            return $this->error('INVALID_ANALYSIS', $e->getMessage(), 422);
        }
    }

    #[Route('/analyses/{id<\d+>}', methods: ['PUT'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function update(int $id, Request $r): JsonResponse
    {
        $a = $this->visible($id);
        if (null === $a) {
            return $this->error('NOT_FOUND', 'Analyse introuvable.', 404);
        }
        try {
            $d = $r->toArray();
            $a->configure(array_key_exists('scopeId', $d) ? $this->scope($d) : $a->getScope(), $d);
            $this->em->flush();

            return new JsonResponse($this->analysis($a));
        } catch (\Throwable $e) {
            return $this->error('INVALID_ANALYSIS', $e->getMessage(), 422);
        }
    }

    #[Route('/analyses/{id<\d+>}/revisions', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function revise(int $id, Request $r): JsonResponse
    {
        $old = $this->visible($id);
        if (null === $old || 'APPROVED' !== $old->getStatus()) {
            return $this->error('NOT_FOUND', 'Baseline approuvée introuvable.', 404);
        }try {
            $d = $r->toArray();
            $u = $this->currentUser->get();
            $a = new RiskAnalysis($u->getOrganization(), $u, $old->getKey(), $old->getVersion() + 1, $old->getMethod(), (string) ($d['title'] ?? $old->getTitle()), $d + ['objectives' => $old->getObjectives(), 'team' => $old->getTeam(), 'milestones' => $old->getMilestones(), 'scenarioIds' => $old->getScenarioIds(), 'scale' => $old->getScale()], $old);
            $a->configure($this->scope($d) ?? $old->getScope(), $d);
            $this->em->persist($a);
            $this->em->flush();

            return new JsonResponse($this->analysis($a), 201);
        } catch (\Throwable $e) {
            return $this->error('INVALID_REVISION', $e->getMessage(), 422);
        }
    }

    #[Route('/analyses/{id<\d+>}/quality', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function quality(int $id): JsonResponse
    {
        $a = $this->visible($id);
        if (null === $a) {
            return $this->error('NOT_FOUND', 'Analyse introuvable.', 404);
        }$findings = $this->qualityFindings($a);
        $score = max(0, 100 - count($findings) * 15);
        try {
            $a->review($findings, $score);
            $this->em->flush();
        } catch (\LogicException $e) {
            if ([] === $findings) {
                return $this->error('INVALID_TRANSITION', $e->getMessage(), 422);
            }
        }

        return new JsonResponse(['score' => $score, 'blockingFindings' => $findings, 'status' => $a->getStatus()]);
    }

    #[Route('/analyses/{id<\d+>}/approve', methods: ['POST'])] #[IsGranted(User::ROLE_ADMIN)]
    public function approve(int $id): JsonResponse
    {
        $a = $this->visible($id);
        if (null === $a) {
            return $this->error('NOT_FOUND', 'Analyse introuvable.', 404);
        }try {
            $a->approve($this->currentUser->get(), ['scenarioIds' => $a->getScenarioIds(), 'scale' => $a->getScale(), 'capturedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)]);
            $this->em->flush();

            return new JsonResponse($this->analysis($a));
        } catch (\LogicException $e) {
            return $this->error('INVALID_APPROVAL', $e->getMessage(), 422);
        }
    }

    #[Route('/compare', methods: ['GET'])]
    public function compare(Request $r): JsonResponse
    {
        $left = $this->visible($r->query->getInt('left'));
        $right = $this->visible($r->query->getInt('right'));
        if (null === $left || null === $right) {
            return $this->error('NOT_FOUND', 'Analyse introuvable.', 404);
        }$l = $left->getScenarioIds();
        $rr = $right->getScenarioIds();

        return new JsonResponse(['left' => $this->analysis($left), 'right' => $this->analysis($right), 'addedScenarioIds' => array_values(array_diff($rr, $l)), 'removedScenarioIds' => array_values(array_diff($l, $rr)), 'completenessDelta' => $right->getCompleteness() - $left->getCompleteness(), 'excluded' => [], 'aggregationRule' => 'EXPLICIT_SET_DIFFERENCE_V1']);
    }

    #[Route('/analyses/{id<\d+>}/artifacts', methods: ['GET'])]
    public function artifacts(int $id, Request $r): JsonResponse
    {
        $a = $this->visible($id);
        if (null === $a) {
            return $this->error('NOT_FOUND', 'Analyse introuvable.', 404);
        }$kind = $r->query->get('kind');
        $items = $this->artifacts->search($this->currentUser->get()->getOrganization(), $a, is_string($kind) ? $kind : null, max(1, $r->query->getInt('page', 1)), min(100, max(1, $r->query->getInt('limit', 25))));

        return new JsonResponse(['items' => array_map($this->artifact(...), $items)]);
    }

    #[Route('/analyses/{id<\d+>}/artifacts', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function artifactCreate(int $id, Request $r): JsonResponse
    {
        $a = $this->visible($id);
        if (null === $a) {
            return $this->error('NOT_FOUND', 'Analyse introuvable.', 404);
        }try {
            $d = $r->toArray();
            $u = $this->currentUser->get();
            $x = new AnalysisArtifact($u->getOrganization(), $a, $u, strtoupper((string) ($d['kind'] ?? '')), (string) ($d['title'] ?? ''), (array) ($d['payload'] ?? []), (string) ($d['idempotencyKey'] ?? ''));
            $this->em->persist($x);
            $this->em->flush();

            return new JsonResponse($this->artifact($x), 201);
        } catch (\Throwable $e) {
            return $this->error('INVALID_ARTIFACT', $e->getMessage(), 422);
        }
    }

    #[Route('/artifacts/{id<\d+>}/approve', methods: ['POST'])] #[IsGranted(User::ROLE_ADMIN)]
    public function artifactApprove(int $id): JsonResponse
    {
        $x = $this->artifacts->find($id);
        if (!$x instanceof AnalysisArtifact || $x->getOrganization() !== $this->currentUser->get()->getOrganization()) {
            return $this->error('NOT_FOUND', 'Artefact introuvable.', 404);
        }try {
            $x->approve($this->currentUser->get());
            $this->em->flush();

            return new JsonResponse($this->artifact($x));
        } catch (\LogicException $e) {
            return $this->error('INVALID_APPROVAL', $e->getMessage(), 422);
        }
    }

    #[Route('/imports/preview', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function importPreview(Request $r): JsonResponse
    {
        $d = $r->toArray();
        $rows = array_values((array) ($d['rows'] ?? []));
        if ([] === $rows || count($rows) > 1000) {
            return $this->error('INVALID_IMPORT', 'Lot limité à 1000 lignes.', 422);
        }$seen = [];
        $results = [];
        foreach ($rows as $i => $row) {
            $key = is_array($row) ? trim((string) ($row['externalId'] ?? '')) : '';
            $valid = '' !== $key && !isset($seen[$key]);
            $results[] = ['row' => $i + 1, 'externalId' => $key, 'valid' => $valid, 'reason' => $valid ? null : ('' === $key ? 'MISSING_ID' : 'DUPLICATE')];
            $seen[$key] = true;
        }

        return new JsonResponse(['dryRun' => true, 'resourceType' => (string) ($d['resourceType'] ?? ''), 'mapping' => (array) ($d['mapping'] ?? []), 'results' => $results, 'valid' => count(array_filter($results, fn ($x) => $x['valid']))]);
    }

    #[Route('/metrics', methods: ['GET'])] #[IsGranted(User::ROLE_ADMIN)]
    public function metrics(): JsonResponse
    {
        $o = $this->currentUser->get()->getOrganization();
        $a = $this->analyses->findBy(['organization' => $o]);
        $art = $this->artifacts->findBy(['organization' => $o]);
        $approved = count(array_filter($a, fn (RiskAnalysis $x) => 'APPROVED' === $x->getStatus()));
        $evidence = count(array_filter($art, fn (AnalysisArtifact $x) => 'EVIDENCE' === $x->getKind()));

        return new JsonResponse(['enabled' => true, 'analyses' => count($a), 'approvedAnalyses' => $approved, 'approvalRate' => [] === $a ? 0 : round($approved / count($a), 4), 'reusedEvidence' => $evidence, 'sensitiveContentIncluded' => false]);
    }

    private function visible(int $id): ?RiskAnalysis
    {
        $a = $this->analyses->visible($id, $this->currentUser->get()->getOrganization());

        return null !== $a && $this->access($a, $this->currentUser->get()) ? $a : null;
    }

    private function access(RiskAnalysis $a, User $u): bool
    {
        return $a->getCreatedBy() === $u || [] !== array_intersect([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN, User::ROLE_AUDITOR], $u->getRoles()) || in_array($u->getId(), array_map('intval', $a->getTeam()), true);
    }

    /** @param array<string, mixed> $d */
    private function scope(array $d): ?Scope
    {
        return empty($d['scopeId']) ? null : $this->em->getRepository(Scope::class)->findOneBy(['id' => (int) $d['scopeId'], 'organization' => $this->currentUser->get()->getOrganization()]);
    }

    /** @return list<string> */
    private function qualityFindings(RiskAnalysis $a): array
    {
        $f = [];
        if ([] === $a->getObjectives()) {
            $f[] = 'OBJECTIVES_REQUIRED';
        }if ([] === $a->getTeam()) {
            $f[] = 'TEAM_REQUIRED';
        }if ([] === $a->getScenarioIds()) {
            $f[] = 'SCENARIOS_REQUIRED';
        }foreach ($a->getScenarioIds() as $id) {
            $risk = $this->em->getRepository(RiskScenario::class)->findOneBy(['id' => $id, 'organization' => $a->getOrganization()]);
            if (!$risk instanceof RiskScenario) {
                $f[] = 'FOREIGN_OR_MISSING_SCENARIO_'.$id;
            }
        }

        return array_values(array_unique($f));
    }

    /** @return array<string, mixed> */
    private function analysis(RiskAnalysis $a): array
    {
        return ['id' => $a->getId(), 'key' => $a->getKey(), 'version' => $a->getVersion(), 'method' => $a->getMethod(), 'title' => $a->getTitle(), 'status' => $a->getStatus(), 'completeness' => $a->getCompleteness(), 'objectives' => $a->getObjectives(), 'team' => $a->getTeam(), 'milestones' => $a->getMilestones(), 'scenarioIds' => $a->getScenarioIds(), 'scale' => $a->getScale(), 'baseline' => $a->getBaseline(), 'qualityFindings' => $a->getQualityFindings(), 'scopeId' => $a->getScope()?->getId(), 'supersedesId' => $a->getSupersedes()?->getId(), 'createdById' => $a->getCreatedBy()->getId(), 'approvedById' => $a->getApprovedBy()?->getId(), 'createdAt' => $a->getCreatedAt()->format(DATE_ATOM), 'approvedAt' => $a->getApprovedAt()?->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function artifact(AnalysisArtifact $x): array
    {
        return ['id' => $x->getId(), 'analysisId' => $x->getAnalysis()?->getId(), 'kind' => $x->getKind(), 'title' => $x->getTitle(), 'payload' => $x->getPayload(), 'status' => $x->getStatus(), 'ownerId' => $x->getOwner()->getId(), 'approvedById' => $x->getApprovedBy()?->getId(), 'idempotencyKey' => $x->getIdempotencyKey(), 'createdAt' => $x->getCreatedAt()->format(DATE_ATOM)];
    }

    private function error(string $c, string $m, int $s): JsonResponse
    {
        return new JsonResponse(['code' => $c, 'message' => $m], $s);
    }
}
