<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Application\XlsxExporter;
use App\Domain\Compliance\TisaxAssessmentSummary;
use App\Entity\ComplianceAssessment;
use App\Repository\ComplianceAssessmentRepository;
use App\Repository\ComplianceResultRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/compliance-assessments/{id<\d+>}/tisax')]
final readonly class TisaxController
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComplianceAssessmentRepository $assessments,
        private ComplianceResultRepository $results,
        private TisaxAssessmentSummary $summary,
        private XlsxExporter $xlsx,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function summary(int $id): JsonResponse
    {
        $assessment = $this->find($id);
        if (!$assessment instanceof ComplianceAssessment) {
            return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Évaluation introuvable.'], 404);
        }

        return new JsonResponse($this->summary->summarize($assessment, $this->results->findForAssessment($assessment)));
    }

    #[Route('.xlsx', methods: ['GET'])]
    public function export(int $id): Response
    {
        $assessment = $this->find($id);
        if (!$assessment instanceof ComplianceAssessment) {
            return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Évaluation introuvable.'], 404);
        }
        $results = $this->results->findForAssessment($assessment);
        $parents = [];
        foreach ($results as $result) {
            $parent = $result->getRequirement()->getParentRequirement();
            if (null !== $parent) {
                $parents[$parent->getId()] = true;
            }
        }
        $rows = [['Module', 'Référence', 'Intitulé', 'Statut', 'Maturité actuelle', 'Maturité cible', 'Écart', 'Commentaire', 'Preuves']];
        foreach ($results as $result) {
            $requirement = $result->getRequirement();
            if (isset($parents[$requirement->getId()])) {
                continue;
            }
            $target = $result->getTargetMaturity() ?? TisaxAssessmentSummary::DEFAULT_TARGET;
            $assessed = 'NOT_ASSESSED' !== $result->getComplianceStatus() && 'NOT_APPLICABLE' !== $result->getComplianceStatus();
            $rows[] = [
                $requirement->getCategory(), $requirement->getReference(), $requirement->getTitle(), $result->getComplianceStatus(),
                $result->getMaturityLevel(), $target, $assessed ? max(0, $target - $result->getMaturityLevel()) : '',
                mb_substr((string) $result->getComment(), 0, 1000), count($result->getEvidence()),
            ];
        }
        $title = trim(sprintf('TISAX %s %s', $assessment->getTisaxLevel() ?? '', $assessment->getParticipantId() ?? ''));
        $content = $this->xlsx->render($title, $this->currentUser->get()->getOrganization()->getName(), $rows);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="tisax-assessment-'.$assessment->getId().'.xlsx"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function find(int $id): ?ComplianceAssessment
    {
        return $this->assessments->findOneVisibleTo($id, $this->currentUser->get());
    }
}
