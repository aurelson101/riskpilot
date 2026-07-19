<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Application\IsmsDocumentStorage;
use App\Entity\IsmsDocument;
use App\Entity\IsmsDocumentAcl;
use App\Entity\IsmsDocumentShare;
use App\Entity\IsmsDocumentVersion;
use App\Entity\User;
use App\Repository\IsmsDocumentRepository;
use App\Repository\UserRepository;
use App\Security\IsmsDocumentAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/isms-documents')]
final readonly class IsmsDocumentController
{
    public function __construct(private IsmsDocumentRepository $documents, private UserRepository $users, private CurrentUser $currentUser, private IsmsDocumentAccess $access, private IsmsDocumentStorage $storage, private EntityManagerInterface $entityManager, private string $appUrl)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->currentUser->get();

        return new JsonResponse(array_map(fn (IsmsDocument $document): array => $this->serialize($document, $user, false), $this->documents->findVisibleTo($user)));
    }

    #[Route('/collaborators', methods: ['GET'])]
    public function collaborators(): JsonResponse
    {
        $actor = $this->currentUser->get();
        $users = $this->users->findBy(['organization' => $actor->getOrganization(), 'status' => User::STATUS_ACTIVE], ['lastName' => 'ASC', 'firstName' => 'ASC']);

        return new JsonResponse(array_map($this->user(...), $users));
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->currentUser->get();
        $document = $this->find($id, $user);

        return null === $document || !$this->access->canRead($document, $user) ? $this->notFound() : new JsonResponse($this->serialize($document, $user, true));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->currentUser->get();
        $input = $this->input($request);
        $error = $this->validate($input);
        if (null !== $error) {
            return $error;
        }
        $owner = $this->owner($input, $user);
        if (null === $owner) {
            return $this->invalid('Propriétaire invalide.');
        }
        $document = new IsmsDocument($user->getOrganization(), $owner, (string) $input['title'], (string) $input['category'], (string) ($input['content'] ?? ''));
        $document->updateMetadata((string) $input['title'], (string) $input['category'], 'DRAFT', (string) ($input['classification'] ?? 'INTERNAL'), (string) ($input['visibility'] ?? IsmsDocument::VISIBILITY_ORGANIZATION), $owner);
        $document->initializeVersion($user, isset($input['versionComment']) ? (string) $input['versionComment'] : 'Création');
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($document, $user, true), 201);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->currentUser->get();
        $document = $this->find($id, $user);
        if (null === $document || !$this->access->canEdit($document, $user)) {
            return $this->notFound();
        }
        $input = $this->input($request);
        $error = $this->validate($input);
        if (null !== $error) {
            return $error;
        }
        $ownerInput = $input;
        $ownerInput['ownerId'] ??= $document->getOwner()->getId();
        $owner = $this->access->canManage($document, $user) ? $this->owner($ownerInput, $user) : $document->getOwner();
        if (null === $owner) {
            return $this->invalid('Propriétaire invalide.');
        }
        $canManage = $this->access->canManage($document, $user);
        $requestedStatus = (string) ($input['status'] ?? $document->getStatus());
        if ('APPROVED' === $requestedStatus && 'APPROVED' !== $document->getStatus()) {
            return $this->invalid('Utilisez l’action d’approbation afin de tracer le valideur et la prochaine revue.');
        }
        if ('ARCHIVED' === $requestedStatus && !$canManage) {
            return $this->invalid('Seul un gestionnaire peut archiver un document.');
        }
        $metadataChanged = $document->updateMetadata(
            (string) $input['title'],
            (string) $input['category'],
            $requestedStatus,
            $canManage ? (string) ($input['classification'] ?? $document->getClassification()) : $document->getClassification(),
            $canManage ? (string) ($input['visibility'] ?? $document->getVisibility()) : $document->getVisibility(),
            $owner,
        );
        $content = (string) ($input['content'] ?? $document->getContent());
        $comment = isset($input['versionComment']) ? (string) $input['versionComment'] : null;
        if ($content !== $document->getContent()) {
            $document->revise($content, $user, $comment);
        } elseif ($metadataChanged) {
            $document->recordRevision($user, $comment ?: 'Mise à jour des métadonnées');
        }
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($document, $user, true));
    }

    #[Route('/{id<\d+>}', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->currentUser->get();
        $document = $this->find($id, $user);
        if (null === $document || !$this->access->canManage($document, $user)) {
            return $this->notFound();
        }
        $storageNames = array_filter(array_merge([$document->getFileStorageName()], array_map(static fn (IsmsDocumentVersion $version): ?string => $version->getFileStorageName(), $document->getVersions()->toArray())));
        $this->entityManager->remove($document);
        $this->entityManager->flush();
        foreach (array_unique($storageNames) as $storageName) {
            $this->storage->delete($storageName);
        }

        return new JsonResponse(null, 204);
    }

    #[Route('/{id<\d+>}/file', methods: ['POST'])]
    public function uploadFile(int $id, Request $request): JsonResponse
    {
        $user = $this->currentUser->get();
        $document = $this->find($id, $user);
        if (null === $document || !$this->access->canEdit($document, $user)) {
            return $this->notFound();
        }
        $file = $request->files->get('file');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return $this->invalid('Sélectionnez un fichier Word.');
        }
        try {
            $stored = $this->storage->store($file);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return $this->invalid($exception->getMessage());
        }
        $previous = $document->getFileStorageName();
        $document->attachFile($file->getClientOriginalName(), $stored['storageName'], $stored['mimeType'], $stored['size'], $stored['checksum']);
        $document->recordRevision($user, null === $previous ? 'Ajout du fichier Word' : 'Remplacement du fichier Word');
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($document, $user, true));
    }

    #[Route('/{id<\d+>}/file', methods: ['GET'])]
    public function downloadFile(int $id): JsonResponse|StreamedResponse
    {
        $user = $this->currentUser->get();
        $document = $this->find($id, $user);
        if (null === $document || !$this->access->canRead($document, $user) || !$document->hasFile()) {
            return $this->notFound();
        }

        return $this->download((string) $document->getFileStorageName(), (string) $document->getFileMimeType(), (string) $document->getFileName());
    }

    #[Route('/{id<\d+>}/file', methods: ['DELETE'])]
    public function deleteFile(int $id): JsonResponse
    {
        $user = $this->currentUser->get();
        $document = $this->find($id, $user);
        if (null === $document || !$this->access->canEdit($document, $user)) {
            return $this->notFound();
        }
        $storageName = $document->getFileStorageName();
        if (null === $storageName) {
            return $this->notFound();
        }
        $document->detachFile();
        $document->recordRevision($user, 'Retrait du fichier Word');
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    #[Route('/{id<\d+>}/versions/{versionId<\d+>}/file', methods: ['GET'])]
    public function downloadVersionFile(int $id, int $versionId): JsonResponse|StreamedResponse
    {
        $user = $this->currentUser->get();
        $document = $this->find($id, $user);
        if (null === $document || !$this->access->canRead($document, $user)) {
            return $this->notFound();
        }
        $version = $document->getVersions()->filter(fn (IsmsDocumentVersion $item): bool => $item->getId() === $versionId)->first();
        if (!$version instanceof IsmsDocumentVersion || !$version->hasFile()) {
            return $this->notFound();
        }

        return $this->download((string) $version->getFileStorageName(), (string) $version->getFileMimeType(), (string) $version->getFileName());
    }

    #[Route('/{id<\d+>}/versions/{versionId<\d+>}/restore', methods: ['POST'])]
    public function restore(int $id, int $versionId): JsonResponse
    {
        $user = $this->currentUser->get();
        $document = $this->find($id, $user);
        if (null === $document || !$this->access->canEdit($document, $user)) {
            return $this->notFound();
        }
        $version = $document->getVersions()->filter(fn (IsmsDocumentVersion $item): bool => $item->getId() === $versionId)->first();
        if (!$version instanceof IsmsDocumentVersion) {
            return $this->notFound();
        }
        $document->revise($version->getContent(), $user, sprintf('Restauration de la version %d', $version->getVersionNumber()));
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($document, $user, true));
    }

    #[Route('/{id<\d+>}/approve', methods: ['POST'])]
    public function approve(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $document = $this->find($id, $actor);
        if (null === $document || !$this->access->canManage($document, $actor)) {
            return $this->notFound();
        }
        if ('' === trim($document->getContent()) && !$document->hasFile()) {
            return $this->invalid('Un document vide ne peut pas être approuvé.');
        }
        $input = $this->input($request);
        try {
            $nextReviewAt = new \DateTimeImmutable((string) ($input['nextReviewAt'] ?? ''));
        } catch (\Exception) {
            return $this->invalid('La date de prochaine revue est invalide.');
        }
        if ($nextReviewAt <= new \DateTimeImmutable('today')) {
            return $this->invalid('La prochaine revue doit être postérieure à aujourd’hui.');
        }
        $document->approve($actor, $nextReviewAt);
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($document, $actor, true));
    }

    #[Route('/{id<\d+>}/acl', methods: ['POST'])]
    public function saveAcl(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $document = $this->find($id, $actor);
        if (null === $document || !$this->access->canManage($document, $actor)) {
            return $this->notFound();
        }
        $input = $this->input($request);
        $userId = filter_var($input['userId'] ?? null, FILTER_VALIDATE_INT);
        $permission = strtoupper((string) ($input['permission'] ?? ''));
        $user = false === $userId ? null : $this->users->findOneBy(['id' => $userId, 'organization' => $actor->getOrganization(), 'status' => User::STATUS_ACTIVE]);
        if (!$user instanceof User || !in_array($permission, IsmsDocumentAcl::PERMISSIONS, true) || $user === $document->getOwner()) {
            return $this->invalid('Utilisateur ou permission ACL invalide.');
        }
        $entry = $document->getAclEntries()->filter(fn (IsmsDocumentAcl $acl): bool => $acl->getUser() === $user)->first();
        if ($entry instanceof IsmsDocumentAcl) {
            $entry->setPermission($permission);
        } else {
            $entry = new IsmsDocumentAcl($document, $user, $permission);
            $this->entityManager->persist($entry);
        }
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($document, $actor, true));
    }

    #[Route('/{id<\d+>}/acl/{aclId<\d+>}', methods: ['DELETE'])]
    public function deleteAcl(int $id, int $aclId): JsonResponse
    {
        $actor = $this->currentUser->get();
        $document = $this->find($id, $actor);
        if (null === $document || !$this->access->canManage($document, $actor)) {
            return $this->notFound();
        }
        $entry = $document->getAclEntries()->filter(fn (IsmsDocumentAcl $acl): bool => $acl->getId() === $aclId)->first();
        if (!$entry instanceof IsmsDocumentAcl) {
            return $this->notFound();
        }
        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    #[Route('/{id<\d+>}/shares', methods: ['POST'])]
    public function createShare(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $document = $this->find($id, $actor);
        if (null === $document || !$this->access->canManage($document, $actor)) {
            return $this->notFound();
        }
        if ('APPROVED' !== $document->getStatus()) {
            return $this->invalid('Seul un document approuvé peut être partagé publiquement.');
        }
        $input = $this->input($request);
        $password = trim((string) ($input['password'] ?? ''));
        $expiresAt = null;
        if (isset($input['expiresAt']) && '' !== $input['expiresAt']) {
            try {
                $expiresAt = new \DateTimeImmutable((string) $input['expiresAt']);
            } catch (\Exception) {
                return $this->invalid('Date d’expiration invalide.');
            } if ($expiresAt <= new \DateTimeImmutable()) {
                return $this->invalid('La date d’expiration doit être future.');
            }
        }
        if ('' !== $password && mb_strlen($password) < 8) {
            return $this->invalid('Le mot de passe doit contenir au moins 8 caractères.');
        }
        if (in_array($document->getClassification(), ['CONFIDENTIAL', 'RESTRICTED'], true) && '' === $password) {
            return $this->invalid('Un mot de passe est obligatoire pour un document confidentiel ou restreint.');
        }
        if ('RESTRICTED' === $document->getClassification() && null === $expiresAt) {
            return $this->invalid('Une expiration est obligatoire pour un document restreint.');
        }
        if ('RESTRICTED' === $document->getClassification() && $expiresAt > new \DateTimeImmutable('+30 days')) {
            return $this->invalid('Un partage restreint ne peut pas dépasser 30 jours.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $share = new IsmsDocumentShare($document, $actor, hash('sha256', $token), '' === $password ? null : password_hash($password, PASSWORD_ARGON2ID), $expiresAt);
        $this->entityManager->persist($share);
        $this->entityManager->flush();

        return new JsonResponse(['share' => $this->share($share), 'url' => rtrim($this->appUrl, '/').'/shared/documents/'.$token], 201);
    }

    #[Route('/{id<\d+>}/shares/{shareId<\d+>}', methods: ['DELETE'])]
    public function revokeShare(int $id, int $shareId): JsonResponse
    {
        $actor = $this->currentUser->get();
        $document = $this->find($id, $actor);
        if (null === $document || !$this->access->canManage($document, $actor)) {
            return $this->notFound();
        }
        $share = $document->getShares()->filter(fn (IsmsDocumentShare $item): bool => $item->getId() === $shareId)->first();
        if (!$share instanceof IsmsDocumentShare) {
            return $this->notFound();
        }
        $share->revoke();
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    private function find(int $id, User $user): ?IsmsDocument
    {
        return $this->documents->findInOrganization($id, $user->getOrganization());
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $input */
    private function owner(array $input, User $actor): ?User
    {
        $ownerId = filter_var($input['ownerId'] ?? $actor->getId(), FILTER_VALIDATE_INT);

        return false === $ownerId ? null : $this->users->findOneBy(['id' => $ownerId, 'organization' => $actor->getOrganization(), 'status' => User::STATUS_ACTIVE]);
    }

    /** @param array<string, mixed> $input */
    private function validate(array $input): ?JsonResponse
    {
        if ('' === trim((string) ($input['title'] ?? '')) || mb_strlen((string) $input['title']) > 200 || '' === trim((string) ($input['category'] ?? '')) || mb_strlen((string) $input['category']) > 80) {
            return $this->invalid('Un titre et une catégorie valides sont requis.');
        }
        if (!in_array((string) ($input['status'] ?? 'DRAFT'), IsmsDocument::STATUSES, true) || !in_array((string) ($input['classification'] ?? 'INTERNAL'), IsmsDocument::CLASSIFICATIONS, true) || !in_array((string) ($input['visibility'] ?? IsmsDocument::VISIBILITY_ORGANIZATION), IsmsDocument::VISIBILITIES, true)) {
            return $this->invalid('Statut, classification ou visibilité invalide.');
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function serialize(IsmsDocument $document, User $user, bool $detail): array
    {
        $data = ['id' => $document->getId(), 'title' => $document->getTitle(), 'category' => $document->getCategory(), 'status' => $document->getStatus(), 'classification' => $document->getClassification(), 'visibility' => $document->getVisibility(), 'excerpt' => $this->excerpt($document), 'owner' => $this->user($document->getOwner()), 'approval' => ['approvedBy' => null === $document->getApprovedBy() ? null : $this->user($document->getApprovedBy()), 'approvedAt' => $document->getApprovedAt()?->format(DATE_ATOM), 'nextReviewAt' => $document->getNextReviewAt()?->format(DATE_ATOM), 'reviewOverdue' => $document->isReviewOverdue()], 'currentVersion' => $document->getCurrentVersion(), 'file' => $document->hasFile() ? ['name' => $document->getFileName(), 'mimeType' => $document->getFileMimeType(), 'size' => $document->getFileSize(), 'checksum' => $document->getFileChecksum()] : null, 'createdAt' => $document->getCreatedAt()->format(DATE_ATOM), 'updatedAt' => $document->getUpdatedAt()->format(DATE_ATOM), 'permissions' => ['read' => $this->access->canRead($document, $user), 'edit' => $this->access->canEdit($document, $user), 'manage' => $this->access->canManage($document, $user)]];
        if ($detail) {
            $data['content'] = $document->getContent();
            $data['versions'] = array_map(fn (IsmsDocumentVersion $version): array => ['id' => $version->getId(), 'versionNumber' => $version->getVersionNumber(), 'comment' => $version->getComment(), 'fileName' => $version->getFileName(), 'fileSize' => $version->getFileSize(), 'fileChecksum' => $version->getFileChecksum(), 'hasFile' => $version->hasFile(), 'author' => $this->user($version->getAuthor()), 'createdAt' => $version->getCreatedAt()->format(DATE_ATOM)], $document->getVersions()->toArray());
            $data['acl'] = array_map(fn (IsmsDocumentAcl $acl): array => ['id' => $acl->getId(), 'permission' => $acl->getPermission(), 'user' => $this->user($acl->getUser())], $document->getAclEntries()->toArray());
            $data['shares'] = $this->access->canManage($document, $user) ? array_map($this->share(...), $document->getShares()->toArray()) : [];
        }

        return $data;
    }

    private function download(string $storageName, string $mimeType, string $fileName): JsonResponse|StreamedResponse
    {
        if (!$this->storage->exists($storageName)) {
            return $this->notFound();
        }
        $response = new StreamedResponse(function () use ($storageName): void {
            $stream = $this->storage->open($storageName);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        });
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName));

        return $response;
    }

    /** @return array<string, mixed> */
    private function share(IsmsDocumentShare $share): array
    {
        return ['id' => $share->getId(), 'enabled' => $share->isEnabled(), 'available' => $share->isAvailable(), 'expired' => $share->isExpired(), 'hasPassword' => $share->hasPassword(), 'expiresAt' => $share->getExpiresAt()?->format(DATE_ATOM), 'accessCount' => $share->getAccessCount(), 'createdAt' => $share->getCreatedAt()->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function user(User $user): array
    {
        return ['id' => $user->getId(), 'email' => $user->getEmail(), 'firstName' => $user->getFirstName(), 'lastName' => $user->getLastName()];
    }

    private function excerpt(IsmsDocument $document): string
    {
        $plainText = preg_replace('/[#*_>`\[\]()!-]+/u', ' ', $document->getContent());
        $plainText = preg_replace('/\s+/u', ' ', trim($plainText ?? $document->getContent()));

        return mb_substr($plainText ?? '', 0, 240);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Document introuvable.'], 404);
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => $message], 422);
    }
}
