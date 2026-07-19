<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\Scope;
use Symfony\Component\Validator\Constraints as Assert;

final class ScopeInput
{
    #[Assert\NotBlank] #[Assert\Length(max: 180)] public string $name = '';
    #[Assert\Length(max: 5000)] public ?string $description = null;
    #[Assert\Choice(choices: Scope::TYPES)] public string $type = 'DEPARTMENT';
    #[Assert\Positive] public ?int $parentScopeId = null;
    #[Assert\Positive] public ?int $ownerId = null;
    #[Assert\Choice(choices: Scope::STATUSES)] public string $status = 'ACTIVE';
}
