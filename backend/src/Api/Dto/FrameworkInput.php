<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\Framework;
use Symfony\Component\Validator\Constraints as Assert;

final class FrameworkInput
{
    #[Assert\NotBlank, Assert\Length(max: 180)] public string $name = '';
    #[Assert\NotBlank, Assert\Length(max: 50)] public string $version = '';
    #[Assert\Length(max: 5000)] public ?string $description = null;
    #[Assert\Length(max: 180)] public ?string $publisher = null;
    #[Assert\Choice(choices: Framework::STATUSES)] public string $status = 'ACTIVE';
}
