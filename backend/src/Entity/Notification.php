<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
class Notification
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private User $recipient;
    #[ORM\Column(length: 50)] private string $type;
    #[ORM\Column(length: 255)] private string $title;
    #[ORM\Column(type: 'text')] private string $message;
    #[ORM\Column(length: 255, nullable: true)] private ?string $link = null;
    #[ORM\Column] private bool $isRead = false;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(User $recipient, string $type, string $title, string $message, ?string $link = null)
    {
        $this->recipient = $recipient;
        $this->organization = $recipient->getOrganization();
        $this->type = $type;
        $this->title = $title;
        $this->message = $message;
        $this->link = $link;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function markRead(): self
    {
        $this->isRead = true;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
