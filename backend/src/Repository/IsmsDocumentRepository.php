<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IsmsDocument;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<IsmsDocument> */
final class IsmsDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IsmsDocument::class);
    }

    /** @return list<IsmsDocument> */
    public function findVisibleTo(User $user): array
    {
        $qb = $this->createQueryBuilder('d')->leftJoin('d.aclEntries', 'acl')->addSelect('acl')->leftJoin('acl.user', 'aclUser')->addSelect('aclUser')->leftJoin('d.owner', 'owner')->addSelect('owner')->andWhere('d.organization = :organization')->setParameter('organization', $user->getOrganization())->orderBy('d.updatedAt', 'DESC');
        if (!$this->isAdmin($user)) {
            $qb->andWhere('d.visibility = :organizationVisibility OR d.owner = :user OR acl.user = :user')->setParameter('organizationVisibility', IsmsDocument::VISIBILITY_ORGANIZATION)->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
    }

    public function findInOrganization(int $id, Organization $organization): ?IsmsDocument
    {
        return $this->createQueryBuilder('d')->leftJoin('d.owner', 'owner')->addSelect('owner')->leftJoin('d.aclEntries', 'acl')->addSelect('acl')->leftJoin('acl.user', 'aclUser')->addSelect('aclUser')->leftJoin('d.shares', 'shares')->addSelect('shares')->andWhere('d.id = :id')->andWhere('d.organization = :organization')->setParameter('id', $id)->setParameter('organization', $organization)->getQuery()->getOneOrNullResult();
    }

    private function isAdmin(User $user): bool
    {
        return in_array(User::ROLE_ADMIN, $user->getRoles(), true) || in_array(User::ROLE_SUPER_ADMIN, $user->getRoles(), true);
    }
}
