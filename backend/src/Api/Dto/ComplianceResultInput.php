<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\ComplianceResult;
use Symfony\Component\Validator\Constraints as Assert;

final class ComplianceResultInput
{
    #[Assert\Range(min: 0, max: 5)] public int $maturityLevel = 0;
    #[Assert\Choice(choices: ComplianceResult::STATUSES)] public string $complianceStatus = 'NOT_ASSESSED';
    #[Assert\Length(max: 10000)] public ?string $comment = null;
    /** @var list<string> */ #[Assert\All([new Assert\Url()])] public array $evidence = [];
    #[Assert\Positive] public ?int $remediationActionId = null;
}
