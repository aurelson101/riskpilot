<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Application\GlobalSearch;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GlobalSearchController
{
    public function __construct(private CurrentUser $currentUser, private GlobalSearch $search, private RateLimiterFactoryInterface $globalSearchLimiter)
    {
    }

    #[Route('/api/search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT);
        $limit = filter_var($request->query->get('limit', '20'), FILTER_VALIDATE_INT);
        $type = $request->query->get('type');
        $sort = $request->query->get('sort', 'relevance');
        $headers = ['Cache-Control' => 'private, no-store'];
        if (mb_strlen($query) < 2 || mb_strlen($query) > 160) {
            return new JsonResponse(['code' => 'INVALID_QUERY', 'message' => 'La recherche doit contenir entre 2 et 160 caractères.'], 422, $headers);
        }
        if (false === $page || $page < 1 || $page > 1000 || false === $limit || $limit < 1 || $limit > 50 || (null !== $type && !isset(GlobalSearch::SOURCES[$type])) || !in_array($sort, ['relevance', 'title'], true)) {
            return new JsonResponse(['code' => 'INVALID_FILTER'], 422, $headers);
        }

        $actor = $this->currentUser->get();
        $allowance = $this->globalSearchLimiter->create($actor->getOrganization()->getId().':'.$actor->getId())->consume();
        if (!$allowance->isAccepted()) {
            return new JsonResponse(['code' => 'RATE_LIMITED'], 429, [...$headers, 'Retry-After' => (string) max(1, $allowance->getRetryAfter()->getTimestamp() - time())]);
        }

        return new JsonResponse($this->search->search($actor, $query, $page, $limit, $type, $sort), 200, $headers);
    }
}
