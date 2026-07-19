<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Repository\AuthSessionRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: Events::JWT_DECODED, method: 'validateSession')]
final readonly class JwtSessionSubscriber
{
    public function __construct(
        private AuthSessionRepository $sessions,
        #[Autowire('%kernel.environment%')] private string $environment,
    ) {
    }

    public function validateSession(JWTDecodedEvent $event): void
    {
        $sessionId = $event->getPayload()['sid'] ?? null;
        if ('test' === $this->environment && null === $sessionId) {
            return;
        }
        if (!is_string($sessionId) || null === $this->sessions->findActiveByPublicId($sessionId)) {
            $event->markAsInvalid();
        }
    }
}
