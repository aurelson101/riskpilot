<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Notification> */
final class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /** @return list<Notification> */
    public function findFor(User $user): array
    {
        return $this->findBy(['recipient' => $user], ['createdAt' => 'DESC'], 50);
    }

    public function findOneFor(int $id, User $user): ?Notification
    {
        return $this->findOneBy(['id' => $id, 'recipient' => $user]);
    }
}
