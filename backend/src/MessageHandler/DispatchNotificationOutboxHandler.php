<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\OrganizationMailer;
use App\Application\EmailTemplateRenderer;
use App\Entity\NotificationOutbox;
use App\Message\DispatchNotificationOutbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DispatchNotificationOutboxHandler
{
    public function __construct(private EntityManagerInterface $entityManager, private OrganizationMailer $mailer, private EmailTemplateRenderer $emailTemplates) {}
    public function __invoke(DispatchNotificationOutbox $message): void
    {
        $item = $this->entityManager->getRepository(NotificationOutbox::class)->find($message->outboxId); if (!$item instanceof NotificationOutbox || 'DISPATCHED' !== $item->getStatus()) return;
        if (!$item->isRecipientEligible()) {
            $item->markCancelled();
            $this->entityManager->flush();
            return;
        }
        if ($item->getAttempts() > NotificationOutbox::MAX_ATTEMPTS) {
            $item->markFailed();
            $this->entityManager->flush();
            return;
        }
        $payload = $item->getPayload();
        $body = $this->emailTemplates->notificationMessage((string) ($payload['message'] ?? ''), $payload['link'] ?? null, $item->getRecipient()->getLocale());
        try { $this->mailer->send((int) $item->getOrganization()->getId(), $item->getRecipient()->getEmail(), (string) ($payload['subject'] ?? ''), $body); $item->markSent(); }
        catch (\Throwable) { $item->markFailed(); }
        $this->entityManager->flush();
    }
}
