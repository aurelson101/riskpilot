<?php

declare(strict_types=1);

namespace App\Domain\Risk;

enum RiskLevel: string
{
    case LOW = 'LOW';
    case MODERATE = 'MODERATE';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';
}
