<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendNotificationEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class SendNotificationEmailHandler
{
    public function __construct(private MailerInterface $mailer)
    {
    }

    public function __invoke(SendNotificationEmail $notification): void
    {
        $this->mailer->send((new Email())->from('notifications@riskpilot.local')->to($notification->recipient)->subject($notification->subject)->text($notification->message));
    }
}
