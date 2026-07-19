<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\Organization;
use Symfony\Component\Validator\Constraints as Assert;

final class UpdateOrganizationInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    public string $name = '';

    #[Assert\Length(max: 5000)]
    public ?string $description = null;

    #[Assert\Choice(choices: [Organization::STATUS_ACTIVE, Organization::STATUS_INACTIVE])]
    public string $status = Organization::STATUS_ACTIVE;

    #[Assert\Range(min: 1, max: 22)] public int $lowMax = 4;
    #[Assert\Range(min: 2, max: 23)] public int $moderateMax = 9;
    #[Assert\Range(min: 3, max: 24)] public int $highMax = 16;
}
