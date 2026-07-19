<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Organization> */
final class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    /** @return list<Organization> */
    public function findVisibleTo(User $actor): array
    {
        if (in_array(User::ROLE_SUPER_ADMIN, $actor->getRoles(), true)) {
            return $this->findBy([], ['name' => 'ASC']);
        }

        return [$actor->getOrganization()];
    }

    public function findOneVisibleTo(int $id, User $actor): ?Organization
    {
        if (in_array(User::ROLE_SUPER_ADMIN, $actor->getRoles(), true)) {
            return $this->find($id);
        }

        return $actor->getOrganization()->getId() === $id ? $actor->getOrganization() : null;
    }
}
