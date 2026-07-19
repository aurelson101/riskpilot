<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\OrganizationMailer;
use App\Message\SendNotificationEmail;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendNotificationEmailHandler
{
    public function __construct(private OrganizationMailer $mailer)
    {
    }

    public function __invoke(SendNotificationEmail $notification): void
    {
        $this->mailer->send($notification->organizationId, $notification->recipient, $notification->subject, $notification->message);
    }
}
