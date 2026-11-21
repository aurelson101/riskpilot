<?php

declare(strict_types=1);

namespace App\Domain\Regulatory;

use App\Entity\IctArrangement;

/**
 * Écarts de complétude d'une ligne du registre d'informations DORA (art. 28 §3). Les champs exigés par les normes
 * techniques d'exécution sont plus nombreux : ce contrôle couvre ceux que RiskPilot saisit.
 */
final class DoraRegisterCompleteness
{
    /** @return list<string> codes d'écart */
    public function gaps(IctArrangement $arrangement, \DateTimeImmutable $now): array
    {
        if ('TERMINATED' === $arrangement->getStatus()) {
            return [];
        }
        $gaps = [];
        foreach (['CONTRACT_REFERENCE_MISSING' => $arrangement->getContractReference(), 'PROVIDER_LEI_MISSING' => $arrangement->getProviderLei(), 'DATA_LOCATION_MISSING' => $arrangement->getDataLocation(), 'SUBCONTRACTORS_NOT_DOCUMENTED' => $arrangement->getSubcontractors(), 'START_DATE_MISSING' => $arrangement->getStartsAt()] as $code => $value) {
            if (null === $value) {
                $gaps[] = $code;
            }
        }
        if (!$arrangement->supportsCriticalFunction()) {
            return $gaps;
        }
        if (null === $arrangement->getFunctionSupported()) {
            $gaps[] = 'FUNCTION_NOT_DESCRIBED';
        }
        if ('NOT_ASSESSED' === $arrangement->getSubstitutability()) {
            $gaps[] = 'SUBSTITUTABILITY_NOT_ASSESSED';
        }
        $tested = $arrangement->getExitPlanTestedAt();
        if (null === $tested) {
            $gaps[] = 'EXIT_PLAN_NOT_TESTED';
        } elseif ($tested < $now->modify('-12 months')) {
            $gaps[] = 'EXIT_PLAN_TEST_OUTDATED';
        }
        $end = $arrangement->getEndsAt();
        if (null !== $end && $end > $now && $end <= $now->modify('+6 months')) {
            $gaps[] = 'CONTRACT_ENDING_SOON';
        }

        return $gaps;
    }
}
