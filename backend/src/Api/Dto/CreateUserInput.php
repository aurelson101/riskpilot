<?php

declare(strict_types=1);

namespace App\Api\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateUserInput
{
    #[Assert\NotBlank(message: 'L’adresse email est obligatoire.')]
    #[Assert\Email(message: 'L’adresse email est invalide.')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    #[Assert\Length(max: 100)]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 100)]
    public string $lastName = '';

    #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.')]
    #[Assert\Length(min: 12, minMessage: 'Le mot de passe doit contenir au moins 12 caractères.')]
    public string $password = '';

    /** @var list<string> */
    #[Assert\Count(min: 1, minMessage: 'Au moins un rôle est obligatoire.')]
    public array $roles = [];

    #[Assert\Positive]
    public ?int $organizationId = null;
}
