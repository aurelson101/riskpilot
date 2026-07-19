<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IsmsDocumentShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<IsmsDocumentShare> */
final class IsmsDocumentShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IsmsDocumentShare::class);
    }

    public function findByToken(string $token): ?IsmsDocumentShare
    {
        return $this->findOneBy(['tokenHash' => hash('sha256', $token)]);
    }
}
