<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CsvExporter;
use App\Application\CurrentUser;
use App\Application\PdfReportRenderer;
use App\Application\XlsxExporter;
use App\Repository\ActionPlanRepository;
use App\Repository\ComplianceAssessmentRepository;
use App\Repository\ComplianceResultRepository;
use App\Repository\RiskScenarioRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/exports')]
final readonly class ExportController
{
    private const STATUS_LABELS = [
        'DRAFT' => 'Brouillon', 'IN_REVIEW' => 'En revue', 'APPROVED' => 'Approuvé',
        'TREATMENT_IN_PROGRESS' => 'Traitement en cours', 'ACCEPTED' => 'Accepté', 'CLOSED' => 'Clos', 'ARCHIVED' => 'Archivé',
        'OPEN' => 'Ouvert', 'PLANNED' => 'Planifié', 'IN_PROGRESS' => 'En cours', 'BLOCKED' => 'Bloqué',
        'COMPLETED' => 'Terminé', 'CANCELLED' => 'Annulé', 'OVERDUE' => 'En retard',
        'COMPLIANT' => 'Conforme', 'PARTIAL' => 'Partiel', 'NON_COMPLIANT' => 'Non conforme',
        'NOT_APPLICABLE' => 'Non applicable', 'NOT_ASSESSED' => 'Non évalué',
    ];

    private const TREATMENT_LABELS = ['REDUCE' => 'Réduire', 'ACCEPT' => 'Accepter', 'TRANSFER' => 'Transférer', 'AVOID' => 'Éviter'];

    public function __construct(
        private CurrentUser $currentUser,
        private RiskScenarioRepository $risks,
        private ActionPlanRepository $actions,
        private ComplianceAssessmentRepository $assessments,
        private ComplianceResultRepository $results,
        private XlsxExporter $xlsxExporter,
        private PdfReportRenderer $pdfExporter,
        private CsvExporter $csvExporter,
    ) {
    }

    #[Route('/risks.{format<csv|xlsx|pdf>}', methods: ['GET'])]
    public function risks(string $format): Response
    {
        return $this->export($format, 'Registre des risques', 'registre-risques', $this->riskRows());
    }

    #[Route('/actions.{format<csv|xlsx|pdf>}', methods: ['GET'])]
    public function actions(string $format): Response
    {
        return $this->export($format, 'Plans d’action', 'plans-actions', $this->actionRows());
    }

    /**
     * Classeur Excel unique : risques, plans d'action et, si une évaluation est indiquée (?assessment=ID), ses résultats de conformité.
     */
    #[Route('/workbook.xlsx', methods: ['GET'])]
    public function workbook(Request $request): Response
    {
        $actor = $this->currentUser->get();
        $sheets = ['Registre des risques' => $this->riskRows(), 'Plans d’action' => $this->actionRows()];
        $assessmentId = $request->query->get('assessment');
        if (null !== $assessmentId) {
            $assessment = ctype_digit((string) $assessmentId) ? $this->assessments->findOneVisibleTo((int) $assessmentId, $actor) : null;
            if (null === $assessment) {
                return new Response('Évaluation introuvable.', Response::HTTP_NOT_FOUND);
            }
            $sheets['Conformité'] = $this->complianceRows($assessment);
        }
        $content = $this->xlsxExporter->renderWorkbook('Synthèse GRC', $actor->getOrganization()->getName(), $sheets, ['Généré par' => trim($actor->getFirstName().' '.$actor->getLastName())]);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$this->filename('synthese-grc', 'xlsx').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return list<list<int|float|string|\DateTimeInterface|null>> */
    private function riskRows(): array
    {
        $rows = [[
            'ID', 'Scénario', 'Description', 'Périmètre', 'Actif', 'Menace', 'Vulnérabilités',
            'Responsable', 'Email responsable', 'Vraisemblance brute', 'Impact brut', 'Score brut',
            'Vraisemblance actuelle', 'Impact actuel', 'Score actuel', 'Vraisemblance résiduelle',
            'Impact résiduel', 'Score résiduel', 'Décision de traitement', 'Code traitement',
            'Statut', 'Code statut', 'Date de révision',
        ]];
        foreach ($this->risks->findForExport($this->currentUser->get()) as $risk) {
            $rows[] = [
                $risk->getId(), $risk->getTitle(), $risk->getDescription(), $risk->getScope()->getName(),
                $risk->getAsset()->getName(), $risk->getThreat()->getName(),
                implode(', ', array_map(static fn ($item): string => $item->getName(), $risk->getVulnerabilities()->toArray())),
                $risk->getRiskOwner()->getFirstName().' '.$risk->getRiskOwner()->getLastName(), $risk->getRiskOwner()->getEmail(),
                $risk->getLikelihood(), $risk->getImpact(), $risk->getGrossRiskScore(),
                $risk->getCurrentLikelihood(), $risk->getCurrentImpact(), $risk->getCurrentRiskScore(),
                $risk->getResidualLikelihood(), $risk->getResidualImpact(), $risk->getResidualRiskScore(),
                self::TREATMENT_LABELS[$risk->getTreatmentDecision()] ?? $risk->getTreatmentDecision(), $risk->getTreatmentDecision(),
                $this->statusLabel($risk->getStatus()), $risk->getStatus(), $risk->getReviewDate(),
            ];
        }

        return $rows;
    }

    /** @return list<list<int|float|string|\DateTimeInterface|null>> */
    private function actionRows(): array
    {
        $rows = [[
            'ID', 'Action', 'Description', 'Risque lié', 'Mesure liée', 'Responsable', 'Email responsable',
            'Priorité', 'Statut', 'Code statut', 'Progression (%)', 'Date de début', 'Échéance',
            'Date de fin', 'Coût estimé', 'Coût réel', 'Réduction de risque attendue', 'Ticket', 'URL ticket',
            'Origine', 'Type', 'Référentiels', 'Exigences', 'Non-conformités', 'Champs personnalisés', 'Preuves',
        ]];
        foreach ($this->actions->findForExport($this->currentUser->get()) as $action) {
            $rows[] = [
                $action->getId(), $action->getTitle(), $action->getDescription(), $action->getRelatedRisk()?->getTitle(),
                $action->getRelatedControl()?->getName(), $action->getOwner()->getFirstName().' '.$action->getOwner()->getLastName(),
                $action->getOwner()->getEmail(), $action->getPriority(), $this->statusLabel($action->getStatus()), $action->getStatus(),
                $action->getProgress(), $action->getStartDate(), $action->getDueDate(),
                $action->getCompletionDate(), $action->getEstimatedCost(), $action->getActualCost(),
                $action->getExpectedRiskReduction(), $action->getTicketNumber(), $action->getTicketUrl(), $action->getOrigin(),
                $action->getActionType(), implode(', ', $action->getFrameworkIds()), implode(', ', $action->getRequirementIds()),
                sprintf('%d audit · %d conformité', $action->getAuditFindings()->count(), $action->getComplianceResults()->count()),
                json_encode($action->getCustomFields(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                implode(', ', $action->getEvidence()),
            ];
        }

        return $rows;
    }

    #[Route('/compliance/{id<\d+>}.{format<csv|xlsx|pdf>}', methods: ['GET'])]
    public function compliance(int $id, string $format): Response
    {
        $assessment = $this->assessments->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $assessment) {
            return new Response('Évaluation introuvable.', Response::HTTP_NOT_FOUND);
        }

        return $this->export($format, 'Évaluation de conformité', 'conformite-'.$id, $this->complianceRows($assessment));
    }

    /** @return list<list<int|float|string|\DateTimeInterface|null>> */
    private function complianceRows(\App\Entity\ComplianceAssessment $assessment): array
    {
        $rows = [[
            'Référentiel', 'Version', 'Périmètre', 'Date évaluation', 'Score global (%)', 'Référence',
            'Exigence', 'Domaine', 'Maturité (0-5)', 'Statut', 'Code statut', 'Commentaire', 'Preuves', 'Action corrective',
        ]];
        foreach ($this->results->findForAssessment($assessment) as $result) {
            $rows[] = [
                $assessment->getFramework()->getName(), $assessment->getFramework()->getVersion(), $assessment->getScope()->getName(),
                $assessment->getAssessmentDate(), $assessment->getGlobalScore(), $result->getRequirement()->getReference(),
                $result->getRequirement()->getTitle(), $result->getRequirement()->getCategory(), $result->getMaturityLevel(),
                $this->statusLabel($result->getComplianceStatus()), $result->getComplianceStatus(), $result->getComment(),
                implode(', ', $result->getEvidence()), $result->getRemediationAction()?->getTitle(),
            ];
        }

        return $rows;
    }

    /** @param list<list<int|float|string|\DateTimeInterface|null>> $rows */
    private function export(string $format, string $title, string $prefix, array $rows): Response
    {
        if ('pdf' === $format) {
            $actor = $this->currentUser->get();
            $content = $this->pdfExporter->renderDataExport(
                $title,
                $actor->getOrganization()->getName(),
                $rows,
                $actor->getLocale(),
                trim($actor->getFirstName().' '.$actor->getLastName()),
            );
            $hash = hash('sha256', $content);

            return new Response($content, Response::HTTP_OK, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$this->filename($prefix, 'pdf').'"',
                'Content-Length' => (string) strlen($content),
                'Content-Language' => $actor->getLocale(),
                'Cache-Control' => 'private, no-store',
                'Digest' => 'sha-256='.base64_encode(hex2bin($hash)),
                'ETag' => '"'.$hash.'"',
                'X-Content-Type-Options' => 'nosniff',
                'X-RiskPilot-Document-SHA256' => $hash,
            ]);
        }
        if ('xlsx' === $format) {
            $content = $this->xlsxExporter->render($title, $this->currentUser->get()->getOrganization()->getName(), $rows);

            return new Response($content, Response::HTTP_OK, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$this->filename($prefix, 'xlsx').'"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $this->csvExporter->response($rows, $this->filename($prefix, 'csv'), $this->currentUser->get()->getLocale());
    }

    private function filename(string $prefix, string $extension): string
    {
        $organization = preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($this->currentUser->get()->getOrganization()->getName()));

        return trim((string) $organization, '-').'-'.$prefix.'-'.(new \DateTimeImmutable())->format('Y-m-d').'.'.$extension;
    }

    private function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }
}
