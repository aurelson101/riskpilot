<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
final readonly class MetricsController
{
    public function __construct(private Connection $connection, private CurrentUser $currentUser)
    {
    }

    #[Route('/api/metrics', methods: ['GET'])]
    public function __invoke(): Response
    {
        $organizationId = (int) $this->currentUser->get()->getOrganization()->getId();
        $metrics = [
            'riskpilot_risks_total' => $this->count('risk_scenarios', $organizationId),
            'riskpilot_actions_total' => $this->count('action_plans', $organizationId),
            'riskpilot_audit_events_total' => $this->count('audit_logs', $organizationId),
            'riskpilot_isms_documents_total' => $this->count('isms_documents', $organizationId),
        ];
        $lines = ['# TYPE riskpilot_build_info gauge', 'riskpilot_build_info{service="api"} 1'];
        foreach ($metrics as $name => $value) {
            $lines[] = '# TYPE '.$name.' gauge';
            $lines[] = $name.' '.$value;
        }

        return new Response(implode("\n", $lines)."\n", Response::HTTP_OK, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    private function count(string $table, int $organizationId): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE organization_id = :organization', $table), ['organization' => $organizationId]);
    }
}
