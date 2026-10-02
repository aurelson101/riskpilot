<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final readonly class GlobalSearch
{
    public const SOURCES = [
        'RISK' => ['risk_scenarios', 'title', 'description', '/risks'],
        'ACTION' => ['action_plans', 'title', 'description', '/actions'],
        'CONTROL' => ['security_controls', 'name', 'description', '/compliance'],
        'DOCUMENT' => ['isms_documents', 'title', 'category', '/isms-documents'],
        'THIRD_PARTY' => ['third_parties', 'name', 'services', '/third-parties'],
    ];

    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, mixed> */
    public function search(User $actor, string $query, int $page, int $limit, ?string $type, string $sort): array
    {
        $escaped = strtr(mb_strtolower($query), ['!' => '!!', '%' => '!%', '_' => '!_']);
        $parameters = ['organization' => $actor->getOrganization()->getId(), 'needle' => '%'.$escaped.'%'];
        $parts = [];
        foreach (self::SOURCES as $kind => [$table, $title, $subtitle, $link]) {
            $acl = '';
            if ('DOCUMENT' === $kind && !array_intersect([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], $actor->getRoles())) {
                $acl = " AND (i.visibility = 'ORGANIZATION' OR i.owner_id = :actor OR EXISTS (SELECT 1 FROM isms_document_acl acl WHERE acl.document_id = i.id AND acl.user_id = :actor))";
                $parameters['actor'] = $actor->getId();
            }
            $parts[$kind] = "SELECT '$kind' AS type, i.id, i.$title AS title, LEFT(COALESCE(i.$subtitle, ''), 400) AS subtitle, '$link' AS link FROM $table i WHERE i.organization_id = :organization AND LOWER(CONCAT(i.$title, ' ', COALESCE(i.$subtitle, ''))) LIKE :needle ESCAPE '!'$acl";
        }
        $union = implode(' UNION ALL ', $parts);
        $counts = array_fill_keys(array_keys(self::SOURCES), 0);
        foreach ($this->connection->fetchAllAssociative("SELECT type, COUNT(*) AS total FROM ($union) matches GROUP BY type", $parameters) as $row) {
            $counts[(string) $row['type']] = (int) $row['total'];
        }
        $total = null === $type ? array_sum($counts) : $counts[$type];
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $selected = null === $type ? $union : $parts[$type];
        $selectedParameters = $parameters;
        if ('DOCUMENT' !== $type && null !== $type) {
            unset($selectedParameters['actor']);
        }
        $order = 'LOWER(title), type, id';
        if ('relevance' === $sort) {
            $order = "CASE WHEN LOWER(title) = :exact THEN 0 WHEN LOWER(title) LIKE :prefix ESCAPE '!' THEN 1 WHEN LOWER(title) LIKE :needle ESCAPE '!' THEN 2 ELSE 3 END, $order";
            $selectedParameters['exact'] = mb_strtolower($query);
            $selectedParameters['prefix'] = $escaped.'%';
        }
        $items = 0 === $total ? [] : $this->connection->fetchAllAssociative("SELECT * FROM ($selected) matches ORDER BY $order LIMIT $limit OFFSET ".(($page - 1) * $limit), $selectedParameters);
        foreach ($items as &$item) {
            $item['id'] = (int) $item['id'];
        }

        return ['items' => $items, 'page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => $pages, 'counts' => $counts];
    }
}
