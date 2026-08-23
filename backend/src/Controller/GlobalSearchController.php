<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Repository\ActionPlanRepository;
use App\Repository\IsmsDocumentRepository;
use App\Repository\RiskScenarioRepository;
use App\Repository\SecurityControlRepository;
use App\Repository\ThirdPartyRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GlobalSearchController
{
    public function __construct(
        private CurrentUser $currentUser,
        private RiskScenarioRepository $risks,
        private ActionPlanRepository $actions,
        private SecurityControlRepository $controls,
        private IsmsDocumentRepository $documents,
        private ThirdPartyRepository $thirdParties,
    ) {
    }

    #[Route('/api/search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $query = mb_strtolower(trim((string) $request->query->get('q', '')));
        if (mb_strlen($query) < 2) {
            return new JsonResponse(['code' => 'QUERY_TOO_SHORT'], 422);
        }
        $actor = $this->currentUser->get();
        $items = [];
        $append = static function (array &$items, string $type, int $id, string $title, string $subtitle, string $link) use ($query): void {
            if (str_contains(mb_strtolower($title.' '.$subtitle), $query)) {
                $items[] = compact('type', 'id', 'title', 'subtitle', 'link');
            }
        };
        foreach ($this->risks->findVisibleTo($actor) as $item) {
            $append($items, 'RISK', (int) $item->getId(), $item->getTitle(), (string) $item->getDescription(), '/risks');
        }
        foreach ($this->actions->findVisibleTo($actor) as $item) {
            $append($items, 'ACTION', (int) $item->getId(), $item->getTitle(), (string) $item->getDescription(), '/actions');
        }
        foreach ($this->controls->findVisibleTo($actor) as $item) {
            $append($items, 'CONTROL', (int) $item->getId(), $item->getName(), (string) $item->getDescription(), '/compliance');
        }
        foreach ($this->documents->findVisibleTo($actor) as $item) {
            $append($items, 'DOCUMENT', (int) $item->getId(), $item->getTitle(), $item->getCategory(), '/isms-documents');
        }
        foreach ($this->thirdParties->findVisibleTo($actor) as $item) {
            $append($items, 'THIRD_PARTY', (int) $item->getId(), $item->getName(), (string) $item->getServices(), '/third-parties');
        }
        usort($items, static fn (array $a, array $b): int => [$a['type'], $a['title']] <=> [$b['type'], $b['title']]);
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(50, max(1, $request->query->getInt('limit', 20)));
        $total = count($items);

        return new JsonResponse(['items' => array_slice($items, ($page - 1) * $limit, $limit), 'page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => max(1, (int) ceil($total / $limit))]);
    }
}
