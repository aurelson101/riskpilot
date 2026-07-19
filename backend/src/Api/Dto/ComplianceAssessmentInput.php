<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\ComplianceAssessment;
use Symfony\Component\Validator\Constraints as Assert;

final class ComplianceAssessmentInput
{
    #[Assert\NotNull, Assert\Positive] public ?int $frameworkId = null;
    #[Assert\NotNull, Assert\Positive] public ?int $scopeId = null;
    #[Assert\NotNull, Assert\Positive] public ?int $assessorId = null;
    #[Assert\NotBlank, Assert\Date] public ?string $assessmentDate = null;
    #[Assert\Choice(choices: ComplianceAssessment::STATUSES)] public string $status = 'DRAFT';
}
