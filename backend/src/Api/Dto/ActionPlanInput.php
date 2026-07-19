<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\ActionPlan;
use Symfony\Component\Validator\Constraints as Assert;

final class ActionPlanInput
{
    #[Assert\NotBlank, Assert\Length(max: 255)] public string $title = '';
    #[Assert\Length(max: 10000)] public ?string $description = null;
    #[Assert\NotNull, Assert\Positive] public ?int $relatedRiskId = null;
    #[Assert\Positive] public ?int $relatedControlId = null;
    #[Assert\NotNull, Assert\Positive] public ?int $ownerId = null;
    #[Assert\Choice(choices: ActionPlan::PRIORITIES)] public string $priority = 'MEDIUM';
    #[Assert\Choice(choices: ActionPlan::STATUSES)] public string $status = 'OPEN';
    #[Assert\Date] public ?string $startDate = null;
    #[Assert\NotBlank, Assert\Date] public ?string $dueDate = null;
    #[Assert\Date] public ?string $completionDate = null;
    #[Assert\Range(min: 0, max: 100)] public int $progress = 0;
    #[Assert\PositiveOrZero] public ?float $estimatedCost = null;
    #[Assert\PositiveOrZero] public ?float $estimatedEffortDays = null;
    #[Assert\PositiveOrZero] public ?float $actualCost = null;
    #[Assert\Range(min: 0, max: 25)] public ?int $expectedRiskReduction = null;
    /** @var list<string> */
    #[Assert\All([new Assert\Url()])] public array $evidence = [];
}
