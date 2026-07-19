<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

final class UpdateUserInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $firstName = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $lastName = '';

    /** @var list<string> */
    #[Assert\Count(min: 1)]
    public array $roles = [];

    #[Assert\Choice(choices: [User::STATUS_ACTIVE, User::STATUS_INACTIVE, User::STATUS_LOCKED])]
    public string $status = User::STATUS_ACTIVE;
}
