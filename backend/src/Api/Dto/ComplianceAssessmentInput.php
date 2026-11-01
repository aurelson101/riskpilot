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
    #[Assert\Choice(choices: ComplianceAssessment::TISAX_LEVELS)] public ?string $tisaxLevel = null;
    /** @var list<string> */ #[Assert\Count(max: 8), Assert\All([new Assert\Choice(choices: ComplianceAssessment::TISAX_OBJECTIVES)])] public array $tisaxObjectives = [];
    #[Assert\Length(max: 255)] public ?string $siteScope = null;
    #[Assert\Length(max: 40), Assert\Regex('/^[A-Za-z0-9-]*$/')] public ?string $participantId = null;
}
