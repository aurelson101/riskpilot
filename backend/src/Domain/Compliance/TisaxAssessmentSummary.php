<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Entity\ComplianceAssessment;
use App\Entity\ComplianceResult;

/**
 * Lecture TISAX d'une évaluation : maturité actuelle contre maturité cible par module.
 * Seules les exigences feuilles sont évaluées ; les chapitres parents ne sont que des regroupements.
 * Une exigence non applicable est exclue ; une exigence non évaluée est « incomplète », jamais conforme.
 */
final class TisaxAssessmentSummary
{
    /** Maturité minimale attendue par défaut pour un label TISAX (niveau « établi »). */
    public const DEFAULT_TARGET = 3;

    /**
     * @param list<ComplianceResult> $results
     *
     * @return array<string, mixed>
     */
    public function summarize(ComplianceAssessment $assessment, array $results): array
    {
        $parents = [];
        foreach ($results as $result) {
            $parent = $result->getRequirement()->getParentRequirement();
            if (null !== $parent) {
                $parents[$this->key($parent)] = true;
            }
        }
        $modules = [];
        foreach ($results as $result) {
            $requirement = $result->getRequirement();
            if (isset($parents[$this->key($requirement)]) || 'NOT_APPLICABLE' === $result->getComplianceStatus()) {
                continue;
            }
            $name = $requirement->getCategory();
            $modules[$name] ??= ['name' => $name, 'total' => 0, 'assessed' => 0, 'meets' => 0, 'blocking' => 0, 'incomplete' => 0, 'gaps' => []];
            $target = $result->getTargetMaturity() ?? self::DEFAULT_TARGET;
            ++$modules[$name]['total'];
            if ('NOT_ASSESSED' === $result->getComplianceStatus()) {
                ++$modules[$name]['incomplete'];
                continue;
            }
            ++$modules[$name]['assessed'];
            if ($result->getMaturityLevel() >= $target) {
                ++$modules[$name]['meets'];
                continue;
            }
            ++$modules[$name]['blocking'];
            $modules[$name]['gaps'][] = ['resultId' => $result->getId(), 'reference' => $requirement->getReference(), 'title' => $requirement->getTitle(), 'maturity' => $result->getMaturityLevel(), 'target' => $target];
        }
        $modules = array_values($modules);
        $totals = ['total' => 0, 'assessed' => 0, 'meets' => 0, 'blocking' => 0, 'incomplete' => 0];
        foreach ($modules as $module) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $module[$key];
            }
        }
        $declarationComplete = null !== $assessment->getTisaxLevel() && [] !== $assessment->getTisaxObjectives() && null !== $assessment->getSiteScope();

        return [
            'level' => $assessment->getTisaxLevel(),
            'objectives' => $assessment->getTisaxObjectives(),
            'siteScope' => $assessment->getSiteScope(),
            'participantId' => $assessment->getParticipantId(),
            'defaultTarget' => self::DEFAULT_TARGET,
            'declarationComplete' => $declarationComplete,
            'modules' => $modules,
            'totals' => $totals,
            // « Prêt » signifie seulement qu'aucun écart bloquant ni exigence incomplète ne subsiste : le label reste délivré par un prestataire d'audit.
            'ready' => $declarationComplete && $totals['total'] > 0 && 0 === $totals['blocking'] && 0 === $totals['incomplete'],
        ];
    }

    private function key(\App\Entity\Requirement $requirement): string
    {
        return null !== $requirement->getId() ? 'id:'.$requirement->getId() : 'object:'.spl_object_id($requirement);
    }
}
