<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\IsmsDocument;
use App\Entity\User;

final class IsmsDocumentAccess
{
    public function canRead(IsmsDocument $document, User $user): bool
    {
        if (User::STATUS_ACTIVE !== $user->getStatus() || $document->getOrganization() !== $user->getOrganization()) {
            return false;
        }
        if ($this->isAdmin($user) || $document->getOwner() === $user || IsmsDocument::VISIBILITY_ORGANIZATION === $document->getVisibility()) {
            return true;
        }

        return null !== $this->permission($document, $user);
    }

    public function canEdit(IsmsDocument $document, User $user): bool
    {
        return User::STATUS_ACTIVE === $user->getStatus() && $document->getOrganization() === $user->getOrganization() && ($this->isAdmin($user) || $document->getOwner() === $user || in_array($this->permission($document, $user), ['EDIT', 'MANAGE'], true));
    }

    public function canManage(IsmsDocument $document, User $user): bool
    {
        return User::STATUS_ACTIVE === $user->getStatus() && $document->getOrganization() === $user->getOrganization() && ($this->isAdmin($user) || $document->getOwner() === $user || 'MANAGE' === $this->permission($document, $user));
    }

    private function permission(IsmsDocument $document, User $user): ?string
    {
        foreach ($document->getAclEntries() as $entry) {
            if ($entry->getUser() === $user) {
                return $entry->getPermission();
            }
        }

        return null;
    }

    private function isAdmin(User $user): bool
    {
        return in_array(User::ROLE_ADMIN, $user->getRoles(), true) || in_array(User::ROLE_SUPER_ADMIN, $user->getRoles(), true);
    }
}
