<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationOutboxRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationOutboxRepository::class)]
#[ORM\Table(name: 'notification_outbox')]
#[ORM\UniqueConstraint(name: 'uniq_notification_outbox_key', columns: ['organization_id', 'idempotency_key'])]
#[ORM\Index(columns: ['status', 'available_at'], name: 'idx_notification_outbox_dispatch')]
class NotificationOutbox
{
    public const STATUSES = ['PENDING', 'DISPATCHED', 'SENT', 'FAILED', 'DEAD_LETTER', 'CANCELLED'];
    public const MAX_ATTEMPTS = 5;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private User $recipient;
    #[ORM\OneToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Notification $notification;
    #[ORM\Column(length: 64)] private string $idempotencyKey;
    /** @var array<string, string> */
    #[ORM\Column(type: 'json')] private array $payload;
    #[ORM\Column(length: 20)] private string $status = 'PENDING';
    #[ORM\Column] private int $attempts = 0;
    #[ORM\Column(nullable: true)] private ?string $lastError = null;
    #[ORM\Column] private \DateTimeImmutable $availableAt;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $sentAt = null;
    /** @param array<string, string> $payload */
    public function __construct(Notification $notification, User $recipient, string $idempotencyKey, array $payload, ?\DateTimeImmutable $availableAt = null)
    {
        if ('' === trim($idempotencyKey)) throw new \InvalidArgumentException('Outbox idempotency key is required.');
        $this->notification = $notification; $this->recipient = $recipient; $this->organization = $recipient->getOrganization(); $this->idempotencyKey = hash('sha256', $idempotencyKey); $this->payload = $payload; $this->availableAt = $availableAt ?? new \DateTimeImmutable(); $this->createdAt = new \DateTimeImmutable();
    }
    public function getId(): ?int { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getRecipient(): User { return $this->recipient; }
    public function getNotification(): Notification { return $this->notification; }
    /** @return array<string, string> */
    public function getPayload(): array { return $this->payload; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getLastError(): ?string { return $this->lastError; }
    public function getAvailableAt(): \DateTimeImmutable { return $this->availableAt; }
    public function isRecipientEligible(): bool
    {
        return User::STATUS_ACTIVE === $this->recipient->getStatus()
            && ($this->recipient->getOrganization() === $this->organization
                || (null !== $this->organization->getId() && $this->recipient->getOrganization()->getId() === $this->organization->getId()));
    }
    public function markDispatched(): void
    {
        if (!in_array($this->status, ['PENDING', 'FAILED'], true)) return;
        if ($this->attempts >= self::MAX_ATTEMPTS) { $this->status = 'DEAD_LETTER'; return; }
        $this->status = 'DISPATCHED'; ++$this->attempts;
    }
    public function markSent(): void
    {
        if ('DISPATCHED' !== $this->status) return;
        $this->status = 'SENT'; $this->lastError = null; $this->sentAt = new \DateTimeImmutable();
    }
    public function markFailed(): void
    {
        if ('DISPATCHED' !== $this->status) return;
        $this->status = $this->attempts >= self::MAX_ATTEMPTS ? 'DEAD_LETTER' : 'FAILED';
        $this->lastError = 'MAIL_SEND_FAILED';
        $this->availableAt = new \DateTimeImmutable('+'.(2 ** min(5, $this->attempts)).' minutes');
    }
    public function markCancelled(): void
    {
        if (!in_array($this->status, ['PENDING', 'FAILED', 'DISPATCHED'], true)) return;
        $this->status = 'CANCELLED'; $this->lastError = 'RECIPIENT_NO_LONGER_ELIGIBLE';
    }
}
