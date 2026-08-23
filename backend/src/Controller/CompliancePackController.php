<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Domain\Compliance\StarterFrameworkCatalog;
use App\Entity\OperationalRecord;
use App\Entity\User;
use App\Repository\OperationalRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/compliance/packs')]
final readonly class CompliancePackController
{
    public function __construct(private CurrentUser $currentUser, private StarterFrameworkCatalog $catalog, private OperationalRecordRepository $records, private EntityManagerInterface $entityManager) {}

    #[Route('/catalog', methods: ['GET'])]
    public function catalog(): JsonResponse
    {
        $items = []; foreach ($this->catalog->keys() as $key) { $definition = $this->catalog->definition($key); $items[] = ['key' => $key, ...$definition, 'contentHash' => hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]; }
        return new JsonResponse(['items' => $items]);
    }

    #[Route('/catalog/{key}/adopt', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function adopt(string $key): JsonResponse
    {
        try { $definition = $this->catalog->definition($key); } catch (\InvalidArgumentException $e) { return new JsonResponse(['code' => 'NOT_FOUND', 'message' => $e->getMessage()], 404); }
        $actor = $this->currentUser->get(); $hash = hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach ($this->records->findForOrganization($actor->getOrganization(), 'REFERENCE_PACK') as $existing) if (($existing->getDetails()['contentHash'] ?? null) === $hash) return new JsonResponse($this->serialize($existing));
        $details = ['code' => strtoupper($key), 'version' => $definition['version'], 'source' => $definition['publisher'], 'license' => 'PUBLIC_SOURCE_OR_METADATA_ONLY', 'effectiveAt' => (new \DateTimeImmutable())->format('Y-m-d'), 'contentHash' => $hash, 'requirements' => $definition['requirements'], 'approved' => false, 'migrationStrategy' => 'EXPLICIT_SELECTION', 'watchStatus' => 'CURRENT'];
        $record = new OperationalRecord($actor->getOrganization(), 'REFERENCE_PACK', $definition['name'].' '.$definition['version'], $details); $record->update($record->getTitle(), 'DRAFT', $details, $actor, null); $this->entityManager->persist($record); $this->entityManager->flush();
        return new JsonResponse($this->serialize($record), 201);
    }

    #[Route('/{id<\d+>}/approve', methods: ['POST'])] #[IsGranted(User::ROLE_ADMIN)]
    public function approve(int $id): JsonResponse
    {
        $actor = $this->currentUser->get(); $record = $this->records->findOneVisible($id, $actor->getOrganization());
        if (null === $record || 'REFERENCE_PACK' !== $record->getType()) return new JsonResponse(['code' => 'NOT_FOUND'], 404);
        if ($record->getOwner() === $actor) return new JsonResponse(['code' => 'SEPARATION_OF_DUTIES', 'message' => 'L’approbateur doit être distinct du propriétaire.'], 422);
        $details = $record->getDetails(); $details['approved'] = true; $details['approvedBy'] = $actor->getEmail(); $details['approvedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM); $details['snapshotHash'] = hash('sha256', json_encode($details['requirements'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $record->update($record->getTitle(), 'ACTIVE', $details, $record->getOwner(), null); $this->entityManager->flush(); return new JsonResponse($this->serialize($record));
    }

    /** @return array<string, mixed> */ private function serialize(OperationalRecord $record): array { return ['id' => $record->getId(), 'title' => $record->getTitle(), 'status' => $record->getStatus(), 'details' => $record->getDetails(), 'ownerId' => $record->getOwner()?->getId(), 'updatedAt' => $record->getUpdatedAt()->format(DATE_ATOM)]; }
}
