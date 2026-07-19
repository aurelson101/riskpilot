<?php

declare(strict_types=1);

namespace App\Domain\Risk;

final class RiskMethodValidator
{
    private const REQUIRED = [
        'SIMPLIFIED' => [],
        'ISO_27005' => ['likelihoodRationale', 'impactRationale', 'controlRationale'],
        'EBIOS_RM' => ['businessValue', 'fearedEvent', 'threatSource', 'strategicScenario', 'operationalScenario'],
    ];

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    public function validate(string $method, array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) && '' !== trim((string) $value)) {
                $normalized[$key] = mb_substr(trim((string) $value), 0, 5000);
            }
        }
        foreach (self::REQUIRED[$method] ?? [] as $required) {
            if (!isset($normalized[$required])) {
                throw new \InvalidArgumentException(sprintf('Le champ méthodologique « %s » est requis pour %s.', $required, $method));
            }
        }

        return $normalized;
    }
}
