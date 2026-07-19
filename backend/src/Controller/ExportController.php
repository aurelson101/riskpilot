<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Repository\ActionPlanRepository;
use App\Repository\ComplianceAssessmentRepository;
use App\Repository\ComplianceResultRepository;
use App\Repository\RiskScenarioRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/exports')]
final readonly class ExportController
{
    public function __construct(private CurrentUser $currentUser, private RiskScenarioRepository $risks, private ActionPlanRepository $actions, private ComplianceAssessmentRepository $assessments, private ComplianceResultRepository $results)
    {
    }

    #[Route('/risks.csv', methods: ['GET'])]
    public function risks(): Response
    {
        $rows = [['ID', 'Scénario', 'Périmètre', 'Actif', 'Score brut', 'Score actuel', 'Score résiduel', 'Traitement', 'Statut']];
        foreach ($this->risks->findVisibleTo($this->currentUser->get()) as $risk) {
            $rows[] = [$risk->getId(), $risk->getTitle(), $risk->getScope()->getName(), $risk->getAsset()->getName(), $risk->getGrossRiskScore(), $risk->getCurrentRiskScore(), $risk->getResidualRiskScore(), $risk->getTreatmentDecision(), $risk->getStatus()];
        }

return $this->csv('risques.csv', $rows);
    }

    #[Route('/actions.csv', methods: ['GET'])]
    public function actions(): Response
    {
        $rows = [['ID', 'Action', 'Risque', 'Responsable', 'Priorité', 'Statut', 'Progression', 'Échéance']];
        foreach ($this->actions->findVisibleTo($this->currentUser->get()) as $action) {
            $rows[] = [$action->getId(), $action->getTitle(), $action->getRelatedRisk()->getTitle(), $action->getOwner()->getFirstName().' '.$action->getOwner()->getLastName(), $action->getPriority(), $action->getStatus(), $action->getProgress(), $action->getDueDate()->format('Y-m-d')];
        }

return $this->csv('plans-actions.csv', $rows);
    }

    #[Route('/compliance/{id<\d+>}.csv', methods: ['GET'])]
    public function compliance(int $id): Response
    {
        $assessment = $this->assessments->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $assessment) {
            return new Response('Évaluation introuvable.', 404);
        } $rows = [['Référence', 'Exigence', 'Domaine', 'Maturité', 'Statut', 'Commentaire', 'Action corrective']];
        foreach ($this->results->findForAssessment($assessment) as $result) {
            $rows[] = [$result->getRequirement()->getReference(), $result->getRequirement()->getTitle(), $result->getRequirement()->getCategory(), $result->getMaturityLevel(), $result->getComplianceStatus(), $result->getComment(), $result->getRemediationAction()?->getTitle()];
        }

return $this->csv('conformite-'.$id.'.csv', $rows);
    }

    /** @param list<list<int|string|null>> $rows */
    private function csv(string $filename, array $rows): Response
    {
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new \RuntimeException('Unable to create export.');
        } fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, array_map($this->safeCell(...), $row), ';', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return new Response(false === $content ? '' : $content, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function safeCell(int|string|null $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@]/', $text) ? "'".$text : $text;
    }
}
