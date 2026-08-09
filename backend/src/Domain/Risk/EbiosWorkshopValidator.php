<?php

declare(strict_types=1);

namespace App\Domain\Risk;

final class EbiosWorkshopValidator
{
    private const REQUIRED = [
        1 => ['context', 'businessValues', 'supportingAssets', 'dreadedEvents', 'securityBaseline'],
        2 => ['riskSources', 'targetObjectives'],
        3 => ['ecosystem', 'strategicScenarios'],
        4 => ['operationalScenarios'],
        5 => ['riskTreatments', 'residualRisks'],
    ];

    /** @param array<string, mixed> $payload
     * @return list<string>
     */
    public function violations(int $number, array $payload): array
    {
        $violations = [];
        foreach (self::REQUIRED[$number] ?? [] as $field) {
            if (!array_key_exists($field, $payload) || null === $payload[$field] || '' === $payload[$field] || [] === $payload[$field]) {
                $violations[] = $field;
            }
        }

        return $violations;
    }
}
