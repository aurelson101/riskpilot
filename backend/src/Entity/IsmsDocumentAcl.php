<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'isms_document_acl')]
#[ORM\UniqueConstraint(name: 'uniq_isms_document_acl_user', columns: ['document_id', 'user_id'])]
class IsmsDocumentAcl
{
    public const PERMISSIONS = ['READ', 'EDIT', 'MANAGE'];
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'aclEntries')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private IsmsDocument $document;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private User $user;
    #[ORM\Column(length: 10)] private string $permission;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(IsmsDocument $document, User $user, string $permission)
    {
        $this->document = $document;
        $this->user = $user;
        $this->permission = $permission;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPermission(): string
    {
        return $this->permission;
    }

    public function setPermission(string $permission): void
    {
        $this->permission = $permission;
    }
}
