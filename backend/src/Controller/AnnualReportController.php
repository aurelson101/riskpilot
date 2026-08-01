<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\AuditLog;
use App\Entity\OperationalRecord;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\OperationalRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/annual-reports')]
final readonly class AnnualReportController
{
    private const MATURITY_DOMAINS = [
        'IAM',
        'GOVERNANCE',
        'RISK_MANAGEMENT',
        'ASSET_MANAGEMENT',
        'VULNERABILITY_MANAGEMENT',
        'DETECTION_RESPONSE',
        'BUSINESS_CONTINUITY',
        'THIRD_PARTIES',
        'COMPLIANCE',
        'AWARENESS',
    ];

    public function __construct(
        private CurrentUser $currentUser,
        private AuditLogRepository $logs,
        private OperationalRecordRepository $records,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/years', methods: ['GET'])]
    public function years(): JsonResponse
    {
        $actor = $this->currentUser->get();
        $years = $this->logs->findYears($actor->getOrganization());
        $years[] = (int) date('Y');
        $versions = [];
        foreach ($this->records->findForOrganization($actor->getOrganization(), 'ANNUAL_REPORT') as $record) {
            $year = (int) ($record->getDetails()['year'] ?? 0);
            if ($year > 0) {
                $years[] = $year;
                $versions[$year][] = $this->savedSummary($record);
            }
        }
        $years = array_values(array_unique($years));
        rsort($years);

        return new JsonResponse(['years' => $years, 'savedReports' => $versions]);
    }

    #[Route('/{year<\d{4}>}', methods: ['GET'])]
    public function preview(int $year): JsonResponse
    {
        if (!$this->validYear($year)) {
            return new JsonResponse(['code' => 'INVALID_YEAR', 'message' => 'Année invalide.'], 422);
        }

        return new JsonResponse($this->build($year));
    }

    #[Route('/{year<\d{4}>}/maturity', methods: ['GET'])]
    public function maturity(int $year): JsonResponse
    {
        if (!$this->validYear($year)) {
            return new JsonResponse(['code' => 'INVALID_YEAR', 'message' => 'Année invalide.'], 422);
        }

        return new JsonResponse($this->maturitySnapshot($year));
    }

    #[Route('/{year<\d{4}>}/maturity', methods: ['PUT'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function updateMaturity(int $year, Request $request): JsonResponse
    {
        if (!$this->validYear($year)) {
            return new JsonResponse(['code' => 'INVALID_YEAR', 'message' => 'Année invalide.'], 422);
        }
        $payload = $request->toArray();
        $received = (array) ($payload['assessments'] ?? []);
        $assessments = [];
        foreach (self::MATURITY_DOMAINS as $domain) {
            $item = (array) ($received[$domain] ?? []);
            $assessed = true === ($item['assessed'] ?? false);
            if (!$assessed) {
                $assessments[$domain] = ['assessed' => false, 'score' => null, 'rationale' => ''];
                continue;
            }
            $score = $item['score'] ?? null;
            if (!is_int($score) && !is_float($score)) {
                return new JsonResponse(['code' => 'INVALID_SCORE', 'message' => sprintf('Un score de 0 à 5 est requis pour %s.', $domain)], 422);
            }
            $score = (float) $score;
            if ($score < 0 || $score > 5 || abs($score * 2 - round($score * 2)) > 0.00001) {
                return new JsonResponse(['code' => 'INVALID_SCORE', 'message' => 'Les scores doivent être compris entre 0 et 5, par pas de 0,5.'], 422);
            }
            $rationale = trim((string) ($item['rationale'] ?? ''));
            if ('' === $rationale) {
                return new JsonResponse(['code' => 'RATIONALE_REQUIRED', 'message' => sprintf('Une justification est requise pour %s.', $domain)], 422);
            }
            $assessments[$domain] = ['assessed' => true, 'score' => $score, 'rationale' => mb_substr($rationale, 0, 1000)];
        }
        $actor = $this->currentUser->get();
        $details = ['year' => $year, 'assessments' => $assessments, 'updatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'updatedBy' => ['id' => $actor->getId(), 'name' => trim($actor->getFirstName().' '.$actor->getLastName())]];
        $record = $this->maturityRecord($year);
        $created = null === $record;
        if (null === $record) {
            $record = new OperationalRecord($actor->getOrganization(), 'ANNUAL_MATURITY', sprintf('Maturité cyber %d', $year), $details);
            $this->entityManager->persist($record);
        }
        $record->update($record->getTitle(), 'ACTIVE', $details, $actor, null);
        $this->entityManager->flush();

        return new JsonResponse($this->maturitySnapshot($year), $created ? 201 : 200);
    }

    #[Route('/{year<\d{4}>}/generate', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function generate(int $year): JsonResponse
    {
        if (!$this->validYear($year)) {
            return new JsonResponse(['code' => 'INVALID_YEAR', 'message' => 'Année invalide.'], 422);
        }
        $actor = $this->currentUser->get();
        $version = 1;
        foreach ($this->records->findForOrganization($actor->getOrganization(), 'ANNUAL_REPORT') as $record) {
            if ($year === (int) ($record->getDetails()['year'] ?? 0)) {
                $version = max($version, (int) ($record->getDetails()['version'] ?? 0) + 1);
            }
        }
        $snapshot = $this->build($year);
        $details = [...$snapshot, 'version' => $version, 'generatedBy' => [
            'id' => $actor->getId(),
            'name' => trim($actor->getFirstName().' '.$actor->getLastName()),
        ]];
        $report = new OperationalRecord($actor->getOrganization(), 'ANNUAL_REPORT', sprintf('Rapport annuel %d — v%d', $year, $version), $details);
        $report->update($report->getTitle(), 'COMPLETED', $details, $actor, null);
        $this->entityManager->persist($report);
        $this->entityManager->flush();

        return new JsonResponse([...$this->savedSummary($report), 'report' => $details], 201);
    }

    #[Route('/saved/{id<\d+>}/export', methods: ['GET'])]
    public function export(int $id, Request $request): Response
    {
        $actor = $this->currentUser->get();
        $record = $this->records->findOneVisible($id, $actor->getOrganization());
        if (!$record instanceof OperationalRecord || 'ANNUAL_REPORT' !== $record->getType()) {
            return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Rapport annuel introuvable.'], 404);
        }
        $format = strtolower((string) $request->query->get('format', 'json'));
        $filename = sprintf('riskpilot-rapport-annuel-%d-v%d', (int) ($record->getDetails()['year'] ?? 0), (int) ($record->getDetails()['version'] ?? 1));
        if ('html' === $format) {
            $payload = htmlspecialchars(json_encode($record->getDetails(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<!doctype html><html lang="fr"><meta charset="utf-8"><title>'.htmlspecialchars($record->getTitle()).'</title><style>body{font:14px system-ui;margin:40px;color:#17324d}pre{white-space:pre-wrap}h1{color:#075985}</style><body><h1>'.htmlspecialchars($record->getTitle()).'</h1><p>Instantané annuel traçable généré par RiskPilot.</p><pre>'.$payload.'</pre></body></html>';

            return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => sprintf('attachment; filename="%s.html"', $filename), 'X-Content-Type-Options' => 'nosniff']);
        }
        $payload = json_encode($record->getDetails(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new Response($payload, 200, ['Content-Type' => 'application/json; charset=UTF-8', 'Content-Disposition' => sprintf('attachment; filename="%s.json"', $filename), 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array<string, mixed> */
    private function build(int $year): array
    {
        $from = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year));
        $until = $from->modify('+1 year');
        $events = $this->logs->findForPeriod($this->currentUser->get()->getOrganization(), $from, $until);
        $months = array_fill(1, 12, 0);
        $actions = [];
        $domains = [];
        $contributors = [];
        $activities = [];
        foreach ($events as $event) {
            $month = (int) $event->getCreatedAt()->format('n');
            ++$months[$month];
            $actions[$event->getAction()] = ($actions[$event->getAction()] ?? 0) + 1;
            $domain = $this->domain($event->getEntityType());
            $domains[$domain] = ($domains[$domain] ?? 0) + 1;
            $user = $event->getUser();
            $actor = null === $user ? 'Système' : trim($user->getFirstName().' '.$user->getLastName());
            $contributors[$actor] = ($contributors[$actor] ?? 0) + 1;
            $activities[] = $this->activity($event, $domain, $actor);
        }
        arsort($domains);
        arsort($actions);
        arsort($contributors);

        return [
            'year' => $year,
            'period' => ['from' => $from->format('Y-m-d'), 'until' => $until->modify('-1 day')->format('Y-m-d')],
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'totals' => ['activities' => count($events), 'contributors' => count($contributors), 'domains' => count(array_filter($domains))],
            'byMonth' => array_map(static fn (int $month, int $count): array => ['month' => $month, 'count' => $count], array_keys($months), array_values($months)),
            'byAction' => $actions,
            'byDomain' => $domains,
            'contributors' => $contributors,
            'activities' => $activities,
            'maturity' => $this->maturitySnapshot($year),
            'methodology' => 'Classification exhaustive des changements consignés dans le journal d’audit du tenant sur la période. Les consultations sans modification ne sont pas comptabilisées.',
        ];
    }

    private function validYear(int $year): bool
    {
        return $year >= 2000 && $year <= (int) date('Y') + 1;
    }

    private function domain(string $entityType): string
    {
        $value = strtolower($entityType);

        return match (true) {
            str_contains($value, 'risk'), str_contains($value, 'threat'), str_contains($value, 'vulnerab') => 'RISKS',
            str_contains($value, 'action'), str_contains($value, 'task'), str_contains($value, 'project') => 'ACTIONS',
            str_contains($value, 'compliance'), str_contains($value, 'control'), str_contains($value, 'regulat') => 'COMPLIANCE',
            str_contains($value, 'audit'), str_contains($value, 'evidence'), str_contains($value, 'document') => 'EVIDENCE',
            str_contains($value, 'third'), str_contains($value, 'supplier') => 'THIRD_PARTIES',
            str_contains($value, 'incident'), str_contains($value, 'continuity'), str_contains($value, 'resilien') => 'RESILIENCE',
            str_contains($value, 'user'), str_contains($value, 'organization'), str_contains($value, 'integration') => 'ADMINISTRATION',
            default => 'GOVERNANCE',
        };
    }

    /** @return array<string, mixed> */
    private function activity(AuditLog $event, string $domain, string $actor): array
    {
        return ['id' => $event->getId(), 'occurredAt' => $event->getCreatedAt()->format(DATE_ATOM), 'domain' => $domain, 'action' => $event->getAction(), 'entityType' => $event->getEntityType(), 'entityId' => $event->getEntityId(), 'actor' => $actor, 'requestId' => $event->getRequestId(), 'sealed' => null !== $event->getEventHash()];
    }

    /** @return array<string, mixed> */
    private function savedSummary(OperationalRecord $record): array
    {
        $details = $record->getDetails();

        return ['id' => $record->getId(), 'year' => (int) ($details['year'] ?? 0), 'version' => (int) ($details['version'] ?? 1), 'title' => $record->getTitle(), 'generatedAt' => $details['generatedAt'] ?? $record->getCreatedAt()->format(DATE_ATOM), 'activities' => (int) ($details['totals']['activities'] ?? 0)];
    }

    /** @return array<string, mixed> */
    private function maturitySnapshot(int $year): array
    {
        $record = $this->maturityRecord($year);
        $saved = $record?->getDetails()['assessments'] ?? [];
        $assessments = [];
        foreach (self::MATURITY_DOMAINS as $domain) {
            $item = (array) ($saved[$domain] ?? []);
            $rationale = (string) ($item['rationale'] ?? '');
            $assessed = true === ($item['assessed'] ?? false) || (!array_key_exists('assessed', $item) && '' !== $rationale);
            $assessments[$domain] = ['assessed' => $assessed, 'score' => $assessed && isset($item['score']) ? (float) $item['score'] : null, 'rationale' => $assessed ? $rationale : ''];
        }
        $assessed = array_filter($assessments, static fn (array $item): bool => true === $item['assessed']);

        return [
            'year' => $year,
            'scale' => ['min' => 0, 'max' => 5, 'step' => 0.5],
            'assessments' => $assessments,
            'average' => [] === $assessed ? null : round(array_sum(array_column($assessed, 'score')) / count($assessed), 2),
            'weaknesses' => array_keys(array_filter($assessments, static fn (array $item): bool => true === $item['assessed'] && $item['score'] <= 2.0)),
            'assessedDomains' => count($assessed),
            'complete' => count($assessed) === count(self::MATURITY_DOMAINS),
            'updatedAt' => $record?->getDetails()['updatedAt'] ?? null,
        ];
    }

    private function maturityRecord(int $year): ?OperationalRecord
    {
        foreach ($this->records->findForOrganization($this->currentUser->get()->getOrganization(), 'ANNUAL_MATURITY') as $record) {
            if ($year === (int) ($record->getDetails()['year'] ?? 0)) {
                return $record;
            }
        }

        return null;
    }
}
