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
    public function findFor(User $user, int $limit = 50, int $offset = 0, bool $unreadOnly = false): array
    {
        $criteria = ['recipient' => $user, 'organization' => $user->getOrganization()];
        if ($unreadOnly) {
            $criteria['isRead'] = false;
        }

        return $this->findBy($criteria, ['createdAt' => 'DESC', 'id' => 'DESC'], $limit, $offset);
    }

    public function findOneFor(int $id, User $user): ?Notification
    {
        return $this->findOneBy(['id' => $id, 'recipient' => $user, 'organization' => $user->getOrganization()]);
    }

    public function countFor(User $user, bool $unreadOnly = false): int
    {
        $criteria = ['recipient' => $user, 'organization' => $user->getOrganization()];
        if ($unreadOnly) {
            $criteria['isRead'] = false;
        }

        return $this->count($criteria);
    }

    public function markAllReadFor(User $user): int
    {
        return (int) $this->createQueryBuilder('n')->update()
            ->set('n.isRead', ':read')->setParameter('read', true)
            ->where('n.recipient = :recipient')->setParameter('recipient', $user)
            ->andWhere('n.organization = :organization')->setParameter('organization', $user->getOrganization())
            ->andWhere('n.isRead = :unread')->setParameter('unread', false)
            ->getQuery()->execute();
    }
}
