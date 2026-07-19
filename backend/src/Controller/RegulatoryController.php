<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\RegulatoryRecord;
use App\Entity\User;
use App\Repository\RegulatoryRecordRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/regulatory-records')] final readonly class RegulatoryController
{
    public function __construct(private RegulatoryRecordRepository $records, private UserRepository $users, private CurrentUser $currentUser, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->response(...), $this->records->findVisibleTo($this->currentUser->get())));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        } $data = $request->toArray();
        $actor = $this->currentUser->get();
        $owner = $this->users->findOneVisibleTo((int) ($data['ownerId'] ?? 0), $actor);
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        } try {
            $record = new RegulatoryRecord($actor->getOrganization(), $owner, (string) ($data['type'] ?? ''), (string) ($data['title'] ?? ''), (array) ($data['details'] ?? []), $this->strings((array) ($data['evidence'] ?? [])), empty($data['dueAt']) ? null : new \DateTimeImmutable((string) $data['dueAt']), empty($data['expiresAt']) ? null : new \DateTimeImmutable((string) $data['expiresAt']));
            $this->entityManager->persist($record);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->response($record), 201);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $record = $this->records->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $record || !$this->canManage()) {
            return null === $record ? $this->notFound() : $this->forbidden();
        } $data = $request->toArray();
        $owner = $this->users->findOneVisibleTo((int) ($data['ownerId'] ?? 0), $this->currentUser->get());
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        } try {
            $record->update((string) ($data['title'] ?? ''), (string) ($data['status'] ?? 'DRAFT'), (array) ($data['details'] ?? []), $this->strings((array) ($data['evidence'] ?? [])), empty($data['dueAt']) ? null : new \DateTimeImmutable((string) $data['dueAt']), empty($data['expiresAt']) ? null : new \DateTimeImmutable((string) $data['expiresAt']), $owner);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->response($record));
    }

    #[Route('/{id<\d+>}/approve', methods: ['POST'])]
    public function approve(int $id): JsonResponse
    {
        $record = $this->records->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $record || !$this->isAdmin()) {
            return null === $record ? $this->notFound() : $this->forbidden();
        } try {
            $record->approve($this->currentUser->get());
            $this->entityManager->flush();
        } catch (\LogicException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->response($record));
    }

    #[Route('/{id<\d+>}', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $record = $this->records->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $record || !$this->canManage()) {
            return null === $record ? $this->notFound() : $this->forbidden();
        }
        $this->entityManager->remove($record);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private function response(RegulatoryRecord $item): array
    {
        return ['id' => $item->getId(), 'type' => $item->getType(), 'title' => $item->getTitle(), 'status' => $item->getStatus(), 'details' => $item->getDetails(), 'evidence' => $item->getEvidence(), 'dueAt' => $item->getDueAt()?->format('Y-m-d'), 'expiresAt' => $item->getExpiresAt()?->format('Y-m-d'), 'owner' => ['id' => $item->getOwner()->getId(), 'name' => trim($item->getOwner()->getFirstName().' '.$item->getOwner()->getLastName())], 'approvedBy' => null === $item->getApprovedBy() ? null : ['id' => $item->getApprovedBy()->getId(), 'name' => trim($item->getApprovedBy()->getFirstName().' '.$item->getApprovedBy()->getLastName())], 'approvedAt' => $item->getApprovedAt()?->format(DATE_ATOM)];
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $values)));
    }

    private function canManage(): bool
    {
        return [] !== array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_RISK_MANAGER, User::ROLE_AUDITOR], $this->currentUser->get()->getRoles());
    }

    private function isAdmin(): bool
    {
        return [] !== array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN], $this->currentUser->get()->getRoles());
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['code' => 'FORBIDDEN', 'message' => 'Droits insuffisants.'], 403);
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_INPUT', 'message' => $message], 422);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Enregistrement introuvable.'], 404);
    }
}
