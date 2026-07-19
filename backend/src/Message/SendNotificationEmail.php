<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SendNotificationEmail
{
    public function __construct(public int $organizationId, public string $recipient, public string $subject, public string $message)
    {
    }
}
