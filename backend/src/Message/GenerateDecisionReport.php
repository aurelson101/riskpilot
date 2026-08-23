<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateDecisionReport
{
    public function __construct(public int $runId)
    {
    }
}
