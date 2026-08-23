<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\OrganizationMailer;
use App\Entity\NotificationOutbox;
use App\Message\DispatchNotificationOutbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DispatchNotificationOutboxHandler
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationMailer $mailer) {}
    public function __invoke(DispatchNotificationOutbox $message): void
    {
        $item = $this->entityManager->getRepository(NotificationOutbox::class)->find($message->outboxId); if (!$item instanceof NotificationOutbox || 'DISPATCHED' !== $item->getStatus()) return;
        $payload = $item->getPayload();
        try { $this->mailer->send((int) $item->getOrganization()->getId(), $item->getRecipient()->getEmail(), (string) ($payload['subject'] ?? ''), (string) ($payload['message'] ?? '')); $item->markSent(); }
        catch (\Throwable $e) { $item->markFailed($e->getMessage()); }
        $this->entityManager->flush();
    }
}
