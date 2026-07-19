<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsDoctrineListener(event: Events::onFlush)]
final readonly class DoctrineChangeSubscriber
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function __invoke(OnFlushEventArgs $event): void
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return;
        }
        $unitOfWork = $event->getObjectManager()->getUnitOfWork();
        $before = $request->attributes->get('_audit_before', []);
        $after = $request->attributes->get('_audit_after', []);
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof AuditLog) {
                continue;
            }
            $key = $entity::class.'#'.$this->identifier($entity);
            foreach ($unitOfWork->getEntityChangeSet($entity) as $field => $change) {
                if (!is_array($change)) {
                    continue;
                }
                [$oldValue, $newValue] = $change;
                $before[$key][$field] = $this->normalize($oldValue);
                $after[$key][$field] = $this->normalize($newValue);
            }
        }
        $request->attributes->set('_audit_before', $before);
        $request->attributes->set('_audit_after', $after);
    }

    private function identifier(object $entity): string
    {
        if (method_exists($entity, 'getId')) {
            return (string) ($entity->getId() ?? 'new');
        }

        return spl_object_hash($entity);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_object($value)) {
            return ['type' => $value::class, 'id' => method_exists($value, 'getId') ? $value->getId() : null];
        }
        if (is_array($value)) {
            return array_map($this->normalize(...), $value);
        }

        return $value;
    }
}
