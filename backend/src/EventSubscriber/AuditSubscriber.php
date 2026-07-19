<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener(event: 'kernel.response')]
final readonly class AuditSubscriber
{
    public function __construct(private Security $security, private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $actor = $this->security->getUser();
        if (!$actor instanceof User || !$request->isMethodSafe() && $event->getResponse()->getStatusCode() >= 400 || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        if ($request->isMethodSafe() || str_starts_with($request->getPathInfo(), '/api/auth/')) {
            return;
        }
        $payload = [];
        if ('' !== $request->getContent()) {
            try {
                $decoded = $request->toArray();
                $payload = $this->redact($decoded);
            } catch (\Throwable) {
                $payload = [];
            }
        }
        $segments = array_values(array_filter(explode('/', $request->getPathInfo())));
        $log = new AuditLog($actor->getOrganization(), $actor, $request->getMethod(), $segments[1] ?? 'api', isset($segments[2]) && ctype_digit($segments[2]) ? $segments[2] : null, $payload, $request->getClientIp(), $request->headers->get('User-Agent'));
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            $normalizedKey = mb_strtolower((string) $key);
            if (str_contains($normalizedKey, 'password') || str_contains($normalizedKey, 'token') || str_contains($normalizedKey, 'secret') || str_contains($normalizedKey, 'authorization') || str_contains($normalizedKey, 'credential')) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
