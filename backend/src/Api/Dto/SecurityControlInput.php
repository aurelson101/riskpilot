<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\SecurityControl;
use Symfony\Component\Validator\Constraints as Assert;

final class SecurityControlInput
{
    #[Assert\NotBlank] #[Assert\Length(max: 180)] public string $name = '';
    #[Assert\Length(max: 5000)] public ?string $description = null;
    #[Assert\NotBlank] #[Assert\Length(max: 100)] public string $category = '';
    #[Assert\Range(min: 0, max: 100)] public int $effectiveness = 0;
    #[Assert\Choice(choices: SecurityControl::STATUSES)] public string $implementationStatus = 'NOT_IMPLEMENTED';
    #[Assert\Positive] public ?int $ownerId = null;
}
