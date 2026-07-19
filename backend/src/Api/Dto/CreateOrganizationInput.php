<?php

declare(strict_types=1);

namespace App\Api\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateOrganizationInput
{
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 180)]
    public string $name = '';

    #[Assert\Length(max: 5000)]
    public ?string $description = null;
}
