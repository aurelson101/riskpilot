<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\Requirement;
use Symfony\Component\Validator\Constraints as Assert;

final class RequirementInput
{
    #[Assert\NotBlank, Assert\Length(max: 100)] public string $reference = '';
    #[Assert\NotBlank, Assert\Length(max: 255)] public string $title = '';
    #[Assert\Length(max: 10000)] public ?string $description = null;
    #[Assert\NotBlank, Assert\Length(max: 120)] public string $category = '';
    #[Assert\Positive] public ?int $parentRequirementId = null;
    #[Assert\Choice(choices: Requirement::STATUSES)] public string $status = 'ACTIVE';
}
