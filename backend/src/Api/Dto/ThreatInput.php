<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\Threat;
use Symfony\Component\Validator\Constraints as Assert;

final class ThreatInput
{
    #[Assert\NotBlank] #[Assert\Length(max: 180)] public string $name = '';
    #[Assert\Length(max: 5000)] public ?string $description = null;
    #[Assert\NotBlank] #[Assert\Length(max: 100)] public string $category = '';
    #[Assert\Length(max: 180)] public ?string $source = null;
    #[Assert\Choice(choices: Threat::STATUSES)] public string $status = 'ACTIVE';
}
