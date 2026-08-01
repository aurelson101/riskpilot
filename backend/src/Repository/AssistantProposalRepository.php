<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssistantProposal;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AssistantProposal> */
final class AssistantProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantProposal::class);
    }

    /** @return list<AssistantProposal> */
    public function findForOrganization(Organization $organization, int $limit = 100): array
    {
        return $this->findBy(['organization' => $organization], ['createdAt' => 'DESC'], min(200, max(1, $limit)));
    }
}
