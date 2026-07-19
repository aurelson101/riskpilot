<?php

declare(strict_types=1);

namespace App\Api\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ChangePasswordInput
{
    #[Assert\NotBlank]
    public string $currentPassword = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 12, minMessage: 'Le nouveau mot de passe doit contenir au moins 12 caractères.')]
    public string $newPassword = '';
}
