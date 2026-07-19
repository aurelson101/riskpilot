<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\PlatformIntegration;
use App\Entity\User;
use App\Repository\PlatformIntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/integrations')] final readonly class PlatformIntegrationController
{
    public function __construct(private CurrentUser $currentUser, private PlatformIntegrationRepository $repository, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $actor = $this->admin();

        return new JsonResponse(['apiVersion' => '1', 'items' => array_map($this->response(...), $this->repository->findForOrganization($actor->getOrganization()))]);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->admin();
        $data = $request->toArray();
        try {
            $item = new PlatformIntegration($actor->getOrganization(), (string) ($data['type'] ?? ''), (string) ($data['provider'] ?? 'GENERIC'), (string) ($data['name'] ?? ''), (array) ($data['configuration'] ?? []), (bool) ($data['enabled'] ?? false));
            $plainSecret = null;
            if (in_array($item->getType(), ['API_KEY', 'WEBHOOK'], true)) {
                $plainSecret = 'rp_'.strtolower($item->getType()).'_'.bin2hex(random_bytes(24));
                $item->setCredential($plainSecret);
            }
            $this->entityManager->persist($item);
            $this->entityManager->flush();

            return new JsonResponse([...$this->response($item), 'secret' => $plainSecret], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($e->getMessage());
        }
    }

    #[Route('/{id}', requirements: ['id' => '\\d+'], methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $actor = $this->admin();
        $item = $this->repository->find($id);
        if (!$item instanceof PlatformIntegration || $item->getOrganization() !== $actor->getOrganization()) {
            return $this->notFound();
        }
        $data = $request->toArray();
        try {
            $item->update((string) ($data['name'] ?? $item->getName()), (array) ($data['configuration'] ?? $item->getConfiguration()), (bool) ($data['enabled'] ?? $item->isEnabled()));
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($e->getMessage());
        }
        $this->entityManager->flush();

        return new JsonResponse($this->response($item));
    }

    #[Route('/{id}', requirements: ['id' => '\\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $actor = $this->admin();
        $item = $this->repository->find($id);
        if (!$item instanceof PlatformIntegration || $item->getOrganization() !== $actor->getOrganization()) {
            return $this->notFound();
        }
        $this->entityManager->remove($item);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    #[Route('/scim/preview', methods: ['POST'])]
    public function scimPreview(Request $request): JsonResponse
    {
        $this->admin();
        $data = $request->toArray();
        $users = (array) ($data['users'] ?? []);
        $valid = array_values(array_filter($users, static fn ($user): bool => is_array($user) && false !== filter_var($user['email'] ?? null, FILTER_VALIDATE_EMAIL)));

        return new JsonResponse(['apiVersion' => '1', 'received' => count($users), 'valid' => count($valid), 'rejected' => count($users) - count($valid), 'dryRun' => true]);
    }

    #[Route('/{id}/webhook-signature', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function webhookSignature(int $id, Request $request): JsonResponse
    {
        $actor = $this->admin();
        $item = $this->repository->find($id);
        if (!$item instanceof PlatformIntegration || $item->getOrganization() !== $actor->getOrganization() || 'WEBHOOK' !== $item->getType()) {
            return $this->notFound();
        }
        $payload = json_encode($request->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        return new JsonResponse(['timestamp' => $timestamp, 'signature' => 'sha256='.$item->sign($payload, $timestamp), 'payload' => $payload]);
    }

    /** @return array<string, mixed> */
    private function response(PlatformIntegration $item): array
    {
        return ['id' => $item->getId(), 'type' => $item->getType(), 'provider' => $item->getProvider(), 'name' => $item->getName(), 'configuration' => $item->getConfiguration(), 'credentialPrefix' => $item->getCredentialPrefix(), 'enabled' => $item->isEnabled(), 'lastUsedAt' => $item->getLastUsedAt()?->format(DATE_ATOM), 'updatedAt' => $item->getUpdatedAt()->format(DATE_ATOM)];
    }

    private function admin(): User
    {
        $actor = $this->currentUser->get();
        if (!in_array(User::ROLE_ADMIN, $actor->getRoles(), true) && !in_array(User::ROLE_SUPER_ADMIN, $actor->getRoles(), true)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }

        return $actor;
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_INTEGRATION', 'message' => $message], 422);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Intégration introuvable.'], 404);
    }
}
