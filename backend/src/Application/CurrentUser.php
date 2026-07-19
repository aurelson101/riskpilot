<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class CurrentUser
{
    public function __construct(private Security $security)
    {
    }

    public function get(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Authenticated RiskPilot user expected.');
        }

        return $user;
    }
}
