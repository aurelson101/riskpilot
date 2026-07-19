<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Domain\Risk\RiskCalculation;
use App\Entity\RiskScenario;
use App\Repository\RiskScenarioRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RiskMatrixController
{
    public function __construct(private RiskScenarioRepository $risks, private CurrentUser $currentUser, private RiskCalculation $calculation)
    {
    }

    #[Route('/api/risk-matrix', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $scoreType = (string) $request->query->get('scoreType', 'current');
        if (!in_array($scoreType, ['gross', 'current', 'residual'], true)) {
            return new JsonResponse(['code' => 'INVALID_SCORE_TYPE', 'message' => 'Type de score invalide.'], 422);
        }
        $actor = $this->currentUser->get();
        $thresholds = $actor->getOrganization()->getRiskThresholds();
        $cells = [];
        for ($impact = 5; $impact >= 1; --$impact) {
            for ($likelihood = 1; $likelihood <= 5; ++$likelihood) {
                $score = $this->calculation->score($likelihood, $impact);
                $cells[$impact.'-'.$likelihood] = ['likelihood' => $likelihood, 'impact' => $impact, 'score' => $score, 'level' => $this->calculation->level($score, $thresholds)->value, 'count' => 0, 'risks' => []];
            }
        }
        foreach ($this->risks->findVisibleTo($actor) as $risk) {
            [$likelihood, $impact] = $this->coordinates($risk, $scoreType);
            $key = $impact.'-'.$likelihood;
            ++$cells[$key]['count'];
            $cells[$key]['risks'][] = ['id' => $risk->getId(), 'title' => $risk->getTitle(), 'status' => $risk->getStatus()];
        }

        return new JsonResponse(['scoreType' => $scoreType, 'thresholds' => $thresholds, 'cells' => array_values($cells)]);
    }

    /** @return array{int, int} */
    private function coordinates(RiskScenario $risk, string $type): array
    {
        return match ($type) {
            'gross' => [$risk->getLikelihood(), $risk->getImpact()], 'residual' => [$risk->getResidualLikelihood(), $risk->getResidualImpact()], default => [$risk->getCurrentLikelihood(), $risk->getCurrentImpact()]
        };
    }
}
