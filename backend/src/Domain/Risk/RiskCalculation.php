<?php

declare(strict_types=1);

namespace App\Domain\Risk;

final readonly class RiskCalculation
{
    public function score(int $likelihood, int $impact): int
    {
        if ($likelihood < 1 || $likelihood > 5 || $impact < 1 || $impact > 5) {
            throw new \InvalidArgumentException('Likelihood and impact must be between 1 and 5.');
        }

        return $likelihood * $impact;
    }

    /** @param array{lowMax: int, moderateMax: int, highMax: int, criticalMax: int} $thresholds */
    public function level(int $score, array $thresholds): RiskLevel
    {
        if ($score < 1 || $score > 25 || $thresholds['lowMax'] >= $thresholds['moderateMax'] || $thresholds['moderateMax'] >= $thresholds['highMax'] || $thresholds['highMax'] >= $thresholds['criticalMax']) {
            throw new \InvalidArgumentException('Invalid score or organization thresholds.');
        }

        return match (true) {
            $score <= $thresholds['lowMax'] => RiskLevel::LOW,
            $score <= $thresholds['moderateMax'] => RiskLevel::MODERATE,
            $score <= $thresholds['highMax'] => RiskLevel::HIGH,
            default => RiskLevel::CRITICAL,
        };
    }
}
