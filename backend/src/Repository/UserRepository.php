<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

/** @extends ServiceEntityRepository<User> */
final class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = :email')
            ->andWhere('u.status = :status')
            ->setParameter('email', mb_strtolower($identifier))
            ->setParameter('status', User::STATUS_ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<User> */
    public function findVisibleTo(User $actor): array
    {
        $builder = $this->createQueryBuilder('u')->orderBy('u.lastName', 'ASC')->addOrderBy('u.firstName', 'ASC');

        if (!in_array(User::ROLE_SUPER_ADMIN, $actor->getRoles(), true)) {
            $builder->andWhere('u.organization = :organization')->setParameter('organization', $actor->getOrganization());
        }

        return $builder->getQuery()->getResult();
    }

    public function findOneVisibleTo(int $id, User $actor): ?User
    {
        $builder = $this->createQueryBuilder('u')->andWhere('u.id = :id')->setParameter('id', $id);

        if (!in_array(User::ROLE_SUPER_ADMIN, $actor->getRoles(), true)) {
            $builder->andWhere('u.organization = :organization')->setParameter('organization', $actor->getOrganization());
        }

        return $builder->getQuery()->getOneOrNullResult();
    }
}
