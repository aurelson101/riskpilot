<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\Notification;
use App\Entity\User;
use App\Message\SendNotificationEmail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class NotificationService
{
    public function __construct(private EntityManagerInterface $entityManager, private MessageBusInterface $bus)
    {
    }

    public function notify(User $recipient, string $type, string $title, string $message, ?string $link = null): void
    {
        $this->entityManager->persist(new Notification($recipient, $type, $title, $message, $link));
        $this->bus->dispatch(new SendNotificationEmail($recipient->getEmail(), $title, $message));
    }
}
