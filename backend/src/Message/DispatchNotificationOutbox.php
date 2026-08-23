<?php

declare(strict_types=1);

namespace App\Message;

final readonly class DispatchNotificationOutbox { public function __construct(public int $outboxId) {} }
