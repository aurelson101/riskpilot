<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Domain\Resilience\Nis2Deadlines;
use App\Entity\SecurityIncident;
use App\Entity\User;
use App\Repository\SecurityIncidentRepository;
use App\Repository\ThirdPartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/nis2')]
final readonly class Nis2Controller
{
    public function __construct(
        private CurrentUser $currentUser,
        private SecurityIncidentRepository $incidents,
        private ThirdPartyRepository $thirdParties,
        private Nis2Deadlines $deadlines,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        $actor = $this->currentUser->get();
        $now = new \DateTimeImmutable();
        $items = [];
        $counts = ['significantOpen' => 0, 'overdue' => 0, 'dueSoon' => 0];
        foreach ($this->incidents->findVisibleTo($actor) as $incident) {
            $steps = $this->deadlines->steps($incident->getDetectedAt(), $incident->isSignificant(), $incident->getEarlyWarningAt(), $incident->getNotifiedAt(), $incident->getFinalReportAt(), $now);
            $worst = $this->deadlines->worstStatus($steps);
            if ($incident->isSignificant() && 'CLOSED' !== $incident->getStatus()) {
                ++$counts['significantOpen'];
                $counts['overdue'] += 'OVERDUE' === $worst ? 1 : 0;
                $counts['dueSoon'] += 'DUE_SOON' === $worst ? 1 : 0;
            }
            if (!$incident->isSignificant() && 'CLOSED' === $incident->getStatus()) {
                continue;
            }
            $items[] = [
                'id' => $incident->getId(), 'title' => $incident->getTitle(), 'severity' => $incident->getSeverity(), 'status' => $incident->getStatus(),
                'category' => $incident->getCategory(), 'significant' => $incident->isSignificant(), 'detectedAt' => $incident->getDetectedAt()->format(DATE_ATOM),
                'worstStatus' => $worst, 'steps' => $steps,
            ];
        }
        $critical = array_values(array_filter($this->thirdParties->findVisibleTo($actor), static fn ($party): bool => in_array($party->getCriticality(), ['HIGH', 'CRITICAL'], true)));
        $organization = $actor->getOrganization();

        return new JsonResponse([
            'entity' => ['type' => $organization->getNis2EntityType(), 'sector' => $organization->getNis2Sector(), 'authority' => $organization->getNis2Authority()],
            'summary' => $counts + ['criticalSuppliers' => count($critical)],
            'incidents' => $items,
            'criticalSuppliers' => array_map(static fn ($party): array => ['id' => $party->getId(), 'name' => $party->getName(), 'criticality' => $party->getCriticality()], array_slice($critical, 0, 20)),
        ]);
    }

    #[Route('/entity', methods: ['PUT'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function entity(Request $request): JsonResponse
    {
        $data = $request->toArray();
        foreach (['type', 'sector', 'authority'] as $field) {
            if (array_key_exists($field, $data) && null !== $data[$field] && !is_string($data[$field])) {
                return new JsonResponse(['code' => 'INVALID_INPUT', 'message' => 'Les champs de classification doivent être des textes.'], 422);
            }
        }
        $organization = $this->currentUser->get()->getOrganization();
        try {
            $organization->configureNis2($data['type'] ?? null, $data['sector'] ?? null, $data['authority'] ?? null);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['code' => 'INVALID_INPUT', 'message' => $exception->getMessage()], 422);
        }
        $this->entityManager->flush();

        return new JsonResponse(['type' => $organization->getNis2EntityType(), 'sector' => $organization->getNis2Sector(), 'authority' => $organization->getNis2Authority()]);
    }

    /** Trame de notification à adapter au formulaire de l'autorité nationale (CSIRT) : elle ne remplace pas la déclaration officielle. */
    #[Route('/incidents/{id<\d+>}/report', methods: ['GET'])]
    public function report(int $id): Response
    {
        $actor = $this->currentUser->get();
        $incident = $this->incidents->findOneVisibleTo($id, $actor);
        if (!$incident instanceof SecurityIncident) {
            return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Incident introuvable.'], 404);
        }
        $steps = $this->deadlines->steps($incident->getDetectedAt(), $incident->isSignificant(), $incident->getEarlyWarningAt(), $incident->getNotifiedAt(), $incident->getFinalReportAt(), new \DateTimeImmutable());
        $organization = $actor->getOrganization();
        $lines = [
            '# Notification d’incident — NIS2 art. 23', '',
            '- Entité : '.$organization->getName().' ('.($organization->getNis2EntityType() ?? 'classification non renseignée').')',
            '- Secteur : '.($organization->getNis2Sector() ?? 'non renseigné'),
            '- Autorité destinataire : '.($organization->getNis2Authority() ?? 'non renseignée'),
            '- Incident : #'.$incident->getId().' — '.$incident->getTitle(),
            '- Gravité : '.$incident->getSeverity().' · Catégorie : '.$incident->getCategory().' · Significatif : '.($incident->isSignificant() ? 'oui' : 'non'),
            '- Détecté le : '.$incident->getDetectedAt()->format(DATE_ATOM), '',
            '## Description', '', $incident->getDescription(), '',
            '## Échéances', '',
        ];
        foreach ($steps as $step) {
            $lines[] = sprintf('- %s (%s) : échéance %s — %s%s', $step['key'], $step['article'], $step['dueAt'], $step['status'], null === $step['doneAt'] ? '' : ', déposé le '.$step['doneAt']);
        }
        $lines = [...$lines, '', '## Éléments à compléter pour l’autorité', '',
            '- Gravité et impact de l’incident, indicateurs de compromission, cause présumée', '- Impact transfrontalier éventuel', '- Mesures d’atténuation appliquées', '',
            '_Trame générée par RiskPilot. À adapter au formulaire officiel de l’autorité nationale._'];

        return new Response(implode("\n", $lines)."\n", Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="nis2-incident-'.$incident->getId().'.md"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
