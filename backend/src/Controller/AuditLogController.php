<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/audit-logs')] #[IsGranted(User::ROLE_ADMIN)]
final readonly class AuditLogController
{
    public function __construct(private AuditLogRepository $logs, private CurrentUser $currentUser, private string $appSecret, private \App\Application\CsvExporter $csv, private \App\Application\XlsxExporter $xlsx, private \App\Application\PdfReportRenderer $pdf)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->serialize(...), $this->logs->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/integrity', methods: ['GET'])]
    public function integrity(): JsonResponse
    {
        $logs = $this->logs->findSealedFor($this->currentUser->get());
        $previousHash = null;
        $brokenAt = null;
        foreach ($logs as $log) {
            if (!$log->verify($previousHash)) {
                $brokenAt = $log->getId();
                break;
            }
            $previousHash = $log->getEventHash();
        }

        return new JsonResponse([
            'valid' => null === $brokenAt,
            'sealedEvents' => count($logs),
            'legacyUnverifiableEvents' => $this->logs->countLegacySealedFor($this->currentUser->get()),
            'brokenAt' => $brokenAt,
            'latestHash' => $previousHash,
            'verifiedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ], null === $brokenAt ? Response::HTTP_OK : Response::HTTP_CONFLICT);
    }

    #[Route('/export', methods: ['GET'])]
    public function export(): Response
    {
        $data = array_map($this->serialize(...), $this->logs->findVisibleTo($this->currentUser->get()));
        $payload = json_encode([
            'exportedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'chainMigration' => [
                'currentHashVersion' => 2,
                'legacyUnverifiableEvents' => $this->logs->countLegacySealedFor($this->currentUser->get()),
                'legacyPolicy' => 'PRESERVED_NOT_RESEALED',
                'statement' => 'Legacy v1 hashes are preserved as historical evidence but cannot be verified. The v2 chain starts without rewriting prior events.',
            ],
            'events' => $data,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $payload, $this->appSecret);

        return new Response($payload, Response::HTTP_OK, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="riskpilot-audit.json"',
            'X-RiskPilot-Signature' => $signature,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Preuve d'audit lisible (CSV, Excel ou PDF) : un événement par ligne, empreintes de la chaîne et verdict d'intégrité
     * calculé au moment de l'export. L'export JSON signé reste la forme de référence pour une vérification automatisée.
     */
    #[Route('/export.{format<csv|xlsx|pdf>}', methods: ['GET'])]
    public function exportEvidence(string $format): Response
    {
        $actor = $this->currentUser->get();
        $sealed = $this->logs->findSealedFor($actor);
        $previous = null;
        $broken = null;
        foreach ($sealed as $log) {
            if (!$log->verify($previous)) {
                $broken = $log->getId();
                break;
            }
            $previous = $log->getEventHash();
        }
        $verdict = null === $broken ? sprintf('chaîne valide (%d événements scellés)', count($sealed)) : sprintf('CHAÎNE ROMPUE à l’événement %d', $broken);
        $rows = [['ID', 'Date', 'Utilisateur', 'Action', 'Ressource', 'Identifiant', 'Adresse IP', 'Requête', 'Empreinte précédente', 'Empreinte']];
        foreach ($this->logs->findVisibleTo($actor) as $log) {
            $user = $log->getUser();
            $rows[] = [$log->getId(), $log->getCreatedAt(), null === $user ? ($log->getActorId() ? 'Compte #'.$log->getActorId() : 'Service') : $user->getEmail(), $log->getAction(), $log->getEntityType(), $log->getEntityId(), $log->getIpAddress(), $log->getRequestId(), $log->getPreviousHash(), $log->getEventHash()];
        }
        $organization = $actor->getOrganization()->getName();
        $by = trim($actor->getFirstName().' '.$actor->getLastName());
        $stamp = (new \DateTimeImmutable())->format('Y-m-d');
        $info = ['Généré par' => $by, 'Intégrité de la chaîne' => $verdict, 'Dernière empreinte' => (string) $previous];
        if ('pdf' === $format) {
            $content = $this->pdf->renderDataExport('Journal d’audit', $organization, $rows, $actor->getLocale(), $by, ['Intégrité de la chaîne' => $verdict, 'Dernière empreinte' => (string) $previous]);

            return new Response($content, Response::HTTP_OK, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="audit-'.$stamp.'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        if ('xlsx' === $format) {
            return new Response($this->xlsx->render('Journal d’audit', $organization, $rows, $info), Response::HTTP_OK, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="audit-'.$stamp.'.xlsx"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        return $this->csv->response($rows, 'audit-'.$stamp.'.csv', $actor->getLocale(), ';', 'Y-m-d H:i:s');
    }

    /** @return array<string, mixed> */
    private function serialize(AuditLog $log): array
    {
        return ['id' => $log->getId(), 'actorId' => $log->getActorId(), 'user' => null === $log->getUser() ? null : ['id' => $log->getUser()->getId(), 'name' => $log->getUser()->getFirstName().' '.$log->getUser()->getLastName(), 'email' => $log->getUser()->getEmail()], 'action' => $log->getAction(), 'entityType' => $log->getEntityType(), 'entityId' => $log->getEntityId(), 'oldValues' => $log->getOldValues(), 'newValues' => $log->getNewValues(), 'ipAddress' => $log->getIpAddress(), 'userAgent' => $log->getUserAgent(), 'requestId' => $log->getRequestId(), 'previousHash' => $log->getPreviousHash(), 'eventHash' => $log->getEventHash(), 'hashVersion' => $log->getHashVersion(), 'createdAt' => $log->getCreatedAt()->format(DATE_ATOM)];
    }
}
