<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ActionPlan;
use App\Entity\PlatformIntegration;
use App\Entity\RiskScenario;
use App\Entity\SecurityControl;
use App\Entity\User;
use App\Repository\ActionPlanRepository;
use App\Repository\PlatformIntegrationRepository;
use App\Repository\RiskScenarioRepository;
use App\Repository\SecurityControlRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/service')]
final readonly class ServiceApiController
{
    public function __construct(
        private PlatformIntegrationRepository $repository,
        private RiskScenarioRepository $risks,
        private SecurityControlRepository $controls,
        private ActionPlanRepository $actions,
        private EntityManagerInterface $entityManager,
        private RateLimiterFactory $serviceApiLimiter,
    ) {
    }

    #[Route('/status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $access = $this->access($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        return $this->response([
            'apiVersion' => '1',
            'status' => 'ok',
            'organizationId' => $access->getOrganization()->getId(),
            'scopes' => $access->getConfiguration()['scopes'] ?? [],
        ]);
    }

    #[Route('/risks', methods: ['GET'])]
    public function risks(Request $request): JsonResponse
    {
        $access = $this->access($request, 'risks:read');
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$limit, $offset] = $this->pagination($request);
        $organization = $access->getOrganization();
        $items = $this->risks->findBy(['organization' => $organization], ['id' => 'ASC'], $limit, $offset);

        return $this->collection(array_map($this->risk(...), $items), $limit, $offset, $this->risks->count(['organization' => $organization]));
    }

    #[Route('/controls', methods: ['GET'])]
    public function controls(Request $request): JsonResponse
    {
        $access = $this->access($request, 'controls:read');
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$limit, $offset] = $this->pagination($request);
        $organization = $access->getOrganization();
        $items = $this->controls->findBy(['organization' => $organization], ['id' => 'ASC'], $limit, $offset);

        return $this->collection(array_map($this->control(...), $items), $limit, $offset, $this->controls->count(['organization' => $organization]));
    }

    #[Route('/actions', methods: ['GET'])]
    public function actions(Request $request): JsonResponse
    {
        $access = $this->access($request, 'actions:read');
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$limit, $offset] = $this->pagination($request);
        $organization = $access->getOrganization();
        $items = $this->actions->findBy(['organization' => $organization], ['id' => 'ASC'], $limit, $offset);

        return $this->collection(array_map($this->action(...), $items), $limit, $offset, $this->actions->count(['organization' => $organization]));
    }

    private function access(Request $request, ?string $requiredScope = null): PlatformIntegration|JsonResponse
    {
        $plain = trim((string) $request->headers->get('X-RiskPilot-Key', ''));
        $prefix = substr($plain, 0, 12);
        $limiter = $this->serviceApiLimiter->create(hash('sha256', $prefix.'|'.($request->getClientIp() ?? 'unknown')))->consume();
        if (!$limiter->isAccepted()) {
            $retryAfter = max(1, $limiter->getRetryAfter()->getTimestamp() - time());
            $response = $this->error('SERVICE_API_RATE_LIMITED', 'Trop de requêtes. Réessayez plus tard.', 429);
            $response->headers->set('Retry-After', (string) $retryAfter);

            return $response;
        }

        $item = '' === $plain ? null : $this->repository->findApiKey($prefix);
        if (!$item instanceof PlatformIntegration || !$item->verifies($plain)) {
            return $this->error('INVALID_SERVICE_KEY', 'Clé de service invalide.', 401);
        }
        $scopes = array_map('strval', (array) ($item->getConfiguration()['scopes'] ?? []));
        if (null !== $requiredScope && !in_array($requiredScope, $scopes, true)) {
            return $this->error('INSUFFICIENT_SCOPE', 'La clé ne possède pas la portée requise.', 403, ['requiredScope' => $requiredScope]);
        }
        if ($item->markUsed()) {
            $this->entityManager->flush();
        }

        return $item;
    }

    /** @return array{int, int} */
    private function pagination(Request $request): array
    {
        return [
            max(1, min(100, $request->query->getInt('limit', 50))),
            max(0, min(100000, $request->query->getInt('offset', 0))),
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function collection(array $items, int $limit, int $offset, int $total): JsonResponse
    {
        $nextOffset = $offset + count($items);

        return $this->response([
            'apiVersion' => '1',
            'items' => $items,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'total' => $total,
                'nextOffset' => $nextOffset < $total ? $nextOffset : null,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function risk(RiskScenario $risk): array
    {
        return [
            'id' => $risk->getId(),
            'title' => $risk->getTitle(),
            'family' => $risk->getFamily(),
            'status' => $risk->getStatus(),
            'currentRiskScore' => $risk->getCurrentRiskScore(),
            'residualRiskScore' => $risk->getResidualRiskScore(),
            'treatmentDecision' => $risk->getTreatmentDecision(),
            'reviewDate' => $risk->getReviewDate()?->format('Y-m-d'),
            'scope' => ['id' => $risk->getScope()->getId(), 'name' => $risk->getScope()->getName()],
            'owner' => $this->owner($risk->getRiskOwner()),
            'updatedAt' => $risk->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function control(SecurityControl $control): array
    {
        return [
            'id' => $control->getId(),
            'name' => $control->getName(),
            'category' => $control->getCategory(),
            'implementationStatus' => $control->getImplementationStatus(),
            'effectiveness' => $control->getEffectiveness(),
            'owner' => $this->owner($control->getOwner()),
        ];
    }

    /** @return array<string, mixed> */
    private function action(ActionPlan $action): array
    {
        return [
            'id' => $action->getId(),
            'title' => $action->getTitle(),
            'status' => $action->getStatus(),
            'priority' => $action->getPriority(),
            'progress' => $action->getProgress(),
            'dueDate' => $action->getDueDate()->format('Y-m-d'),
            'owner' => $this->owner($action->getOwner()),
            'relatedRiskId' => $action->getRelatedRisk()?->getId(),
            'updatedAt' => $action->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array{id: int|null, name: string}|null */
    private function owner(?User $user): ?array
    {
        if (null === $user) {
            return null;
        }

        return ['id' => $user->getId(), 'name' => trim($user->getFirstName().' '.$user->getLastName())];
    }

    /** @param array<string, mixed> $extra */
    private function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return $this->response(['code' => $code, 'message' => $message, ...$extra], $status);
    }

    /** @param array<string, mixed> $payload */
    private function response(array $payload, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
