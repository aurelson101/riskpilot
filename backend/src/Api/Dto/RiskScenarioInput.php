<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\RiskScenario;
use Symfony\Component\Validator\Constraints as Assert;

final class RiskScenarioInput
{
    #[Assert\NotBlank] #[Assert\Length(max: 255)] public string $title = '';
    #[Assert\Length(max: 10000)] public ?string $description = null;
    #[Assert\NotBlank] #[Assert\Length(max: 100)] public string $family = 'GENERAL';
    #[Assert\Choice(choices: RiskScenario::METHODS)] public string $analysisMethod = 'SIMPLIFIED';
    public bool $strategic = false;
    /** @var array<string, string> */ public array $methodData = [];
    #[Assert\NotNull] #[Assert\Positive] public ?int $scopeId = null;
    #[Assert\NotNull] #[Assert\Positive] public ?int $assetId = null;
    #[Assert\NotNull] #[Assert\Positive] public ?int $threatId = null;
    /** @var list<int> */ #[Assert\All([new Assert\Positive()])] public array $vulnerabilityIds = [];
    /** @var list<int> */ #[Assert\All([new Assert\Positive()])] public array $currentControlIds = [];
    #[Assert\NotNull] #[Assert\Positive] public ?int $riskOwnerId = null;
    #[Assert\Range(min: 1, max: 5)] public int $likelihood = 1;
    #[Assert\Range(min: 1, max: 5)] public int $impact = 1;
    #[Assert\Range(min: 1, max: 5)] public int $currentLikelihood = 1;
    #[Assert\Range(min: 1, max: 5)] public int $currentImpact = 1;
    #[Assert\Range(min: 1, max: 5)] public int $residualLikelihood = 1;
    #[Assert\Range(min: 1, max: 5)] public int $residualImpact = 1;
    #[Assert\Choice(choices: RiskScenario::TREATMENTS)] public string $treatmentDecision = 'REDUCE';
    #[Assert\Choice(choices: RiskScenario::STATUSES)] public string $status = 'DRAFT';
    #[Assert\Date] public ?string $reviewDate = null;
}
