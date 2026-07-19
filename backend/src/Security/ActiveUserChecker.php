<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ActiveUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && User::STATUS_ACTIVE !== $user->getStatus()) {
            throw new CustomUserMessageAccountStatusException('Ce compte utilisateur est désactivé.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
