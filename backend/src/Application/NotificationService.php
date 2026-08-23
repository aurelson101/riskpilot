<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\Notification;
use App\Entity\NotificationOutbox;
use App\Entity\User;
use App\Repository\NotificationOutboxRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class NotificationService
{
    public function __construct(private EntityManagerInterface $entityManager, private NotificationOutboxRepository $outbox)
    {
    }

    public function notify(User $recipient, string $type, string $title, string $message, ?string $link = null, ?string $idempotencyKey = null): void
    {
        $key = $idempotencyKey ?? implode('|', [$recipient->getId(), $type, $title, (new \DateTimeImmutable())->format('Y-m-d')]);
        if ($this->outbox->existsForKey($recipient->getOrganization(), $key)) {
            return;
        }

        $notification = new Notification($recipient, $type, $title, $message, $link);
        $this->entityManager->persist($notification);
        $this->entityManager->persist(new NotificationOutbox($notification, $recipient, $key, ['subject' => $title, 'message' => $message, 'reason' => $type, 'link' => $link ?? '']));
    }
}
