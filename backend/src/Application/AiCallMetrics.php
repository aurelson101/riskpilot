<?php

declare(strict_types=1);

namespace App\Application;

/** Métadonnées techniques d'un appel fournisseur, renseignées par AiCopilotClient. */
final class AiCallMetrics
{
    public ?string $returnedModel = null;
    public int $inputTokens = 0;
    public int $outputTokens = 0;
    public int $attempts = 0;
    public ?int $providerStatus = null;
}
