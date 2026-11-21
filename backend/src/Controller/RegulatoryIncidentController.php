<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Domain\Regulatory\RegulatoryDeadlines;
use App\Entity\AiSystem;
use App\Entity\DigitalProduct;
use App\Entity\RegulatoryIncident;
use App\Entity\User;
use App\Repository\AiSystemRepository;
use App\Repository\DigitalProductRepository;
use App\Repository\RegulatoryIncidentRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Incidents et vulnérabilités à notifier : DORA (art. 19), CRA (art. 14) et AI Act (art. 73). NIS2 reste traité par {@see Nis2Controller}. */
#[Route('/api/regulatory-incidents')]
final readonly class RegulatoryIncidentController
{
    private const TITLES = [
        RegulatoryDeadlines::DORA => 'Notification d’incident majeur — DORA art. 19',
        RegulatoryDeadlines::CRA_VULNERABILITY => 'Signalement de vulnérabilité activement exploitée — CRA art. 14',
        RegulatoryDeadlines::CRA_INCIDENT => 'Signalement d’incident grave — CRA art. 14',
        RegulatoryDeadlines::AI_ACT => 'Signalement d’incident grave — AI Act art. 73',
    ];

    public function __construct(
        private CurrentUser $currentUser,
        private RegulatoryIncidentRepository $incidents,
        private AiSystemRepository $aiSystems,
        private DigitalProductRepository $products,
        private UserRepository $users,
        private RegulatoryDeadlines $deadlines,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $regime = $request->query->getString('regime');
        if ('' !== $regime && !in_array($regime, RegulatoryDeadlines::REGIMES, true)) {
            return $this->invalid('Régime inconnu.');
        }
        $now = new \DateTimeImmutable();
        $items = [];
        $summary = ['open' => 0, 'overdue' => 0, 'dueSoon' => 0];
        foreach ($this->incidents->findVisibleTo($this->currentUser->get()) as $incident) {
            if ('' !== $regime && $incident->getRegime() !== $regime) {
                continue;
            }
            $item = $this->item($incident, $now);
            if ('OPEN' === $incident->getStatus() && $incident->isApplicable()) {
                ++$summary['open'];
                $summary['overdue'] += 'OVERDUE' === $item['worstStatus'] ? 1 : 0;
                $summary['dueSoon'] += 'DUE_SOON' === $item['worstStatus'] ? 1 : 0;
            }
            $items[] = $item;
        }

        return new JsonResponse(['summary' => $summary, 'items' => $items]);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $data = $request->toArray();
        $owner = array_key_exists('ownerId', $data) ? $this->users->findOneVisibleTo((int) $data['ownerId'], $actor) : $actor;
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        }
        try {
            $incident = new RegulatoryIncident($actor->getOrganization(), $owner, (string) ($data['regime'] ?? ''), (string) ($data['title'] ?? ''), (string) ($data['description'] ?? ''), $this->requiredDate($data['awareAt'] ?? null, 'La prise de connaissance'));
            $this->apply($incident, $data, $actor);
        } catch (\InvalidArgumentException|\DateMalformedStringException $exception) {
            return $this->invalid($exception->getMessage());
        }
        $this->entityManager->persist($incident);
        $this->entityManager->flush();

        return new JsonResponse($this->item($incident, new \DateTimeImmutable()), 201);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function update(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $incident = $this->incidents->findOneVisibleTo($id, $actor);
        if (!$incident instanceof RegulatoryIncident) {
            return $this->notFound();
        }
        $data = $request->toArray();
        $owner = array_key_exists('ownerId', $data) ? $this->users->findOneVisibleTo((int) $data['ownerId'], $actor) : $incident->getOwner();
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        }
        try {
            $incident->describe($owner, (string) ($data['title'] ?? $incident->getTitle()), (string) ($data['description'] ?? $incident->getDescription()), array_key_exists('awareAt', $data) ? $this->requiredDate($data['awareAt'], 'La prise de connaissance') : $incident->getAwareAt());
            $this->apply($incident, $data, $actor);
        } catch (\InvalidArgumentException|\DateMalformedStringException $exception) {
            return $this->invalid($exception->getMessage());
        }
        $this->entityManager->flush();

        return new JsonResponse($this->item($incident, new \DateTimeImmutable()));
    }

    /** Trame de notification à adapter au formulaire de l'autorité compétente : elle ne remplace pas la déclaration officielle. */
    #[Route('/{id<\d+>}/report', methods: ['GET'])]
    public function report(int $id): Response
    {
        $actor = $this->currentUser->get();
        $incident = $this->incidents->findOneVisibleTo($id, $actor);
        if (!$incident instanceof RegulatoryIncident) {
            return $this->notFound();
        }
        $item = $this->item($incident, new \DateTimeImmutable());
        $lines = [
            '# '.self::TITLES[$incident->getRegime()], '',
            '- Entité : '.$actor->getOrganization()->getName(),
            '- Dossier : #'.$incident->getId().' — '.$incident->getTitle(),
            '- Prise de connaissance : '.$incident->getAwareAt()->format(DATE_ATOM),
            '- Statut : '.$incident->getStatus().' · Applicable : '.($incident->isApplicable() ? 'oui' : 'non'),
        ];
        if (null !== $incident->getAiSystem()) {
            $lines[] = '- Système d’IA : '.$incident->getAiSystem()->getName();
        }
        if (null !== $incident->getProduct()) {
            $lines[] = '- Produit : '.$incident->getProduct()->getName().' '.$incident->getProduct()->getVersion();
        }
        $lines = [...$lines, '', '## Description', '', $incident->getDescription(), '', '## Échéances', ''];
        foreach ($item['steps'] as $step) {
            $lines[] = sprintf('- %s (%s) : échéance %s%s — %s%s', $step['key'], $step['article'], $step['dueAt'], $step['provisional'] ? ' (estimée)' : '', $step['status'], null === $step['doneAt'] ? '' : ', déposé le '.$step['doneAt']);
        }
        $lines = [...$lines, '', '_Trame générée par RiskPilot. Les délais sont des repères : l’autorité compétente et les textes applicables font foi._'];

        return new Response(implode("\n", $lines)."\n", Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="notification-'.$incident->getId().'.md"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function apply(RegulatoryIncident $incident, array $data, User $actor): void
    {
        $facts = $incident->deadlineFacts();
        foreach (['widespread', 'criticalInfrastructure', 'death', 'applicable'] as $flag) {
            if (array_key_exists($flag, $data) && !is_bool($data[$flag])) {
                throw new \InvalidArgumentException(sprintf('Le champ %s doit être un booléen.', $flag));
            }
        }
        $incident->qualify(
            $data['applicable'] ?? $incident->isApplicable(),
            array_key_exists('classifiedAt', $data) ? $this->optionalDate($data['classifiedAt'], 'La qualification') : $facts['classifiedAt'],
            array_key_exists('correctiveAvailableAt', $data) ? $this->optionalDate($data['correctiveAvailableAt'], 'Le correctif') : $facts['correctiveAvailableAt'],
            $data['widespread'] ?? $facts['widespread'],
            $data['criticalInfrastructure'] ?? $facts['criticalInfrastructure'],
            $data['death'] ?? $facts['death'],
        );
        $completed = $incident->completedDates();
        if (array_key_exists('completed', $data)) {
            if (!is_array($data['completed'])) {
                throw new \InvalidArgumentException('Les dépôts doivent être un objet étape → date.');
            }
            foreach ($data['completed'] as $key => $value) {
                if (null === $value) {
                    unset($completed[(string) $key]);
                } else {
                    $completed[(string) $key] = $this->requiredDate($value, 'La date de dépôt');
                }
            }
        }
        $incident->record($completed, (string) ($data['status'] ?? $incident->getStatus()));
        $aiSystem = $incident->getAiSystem();
        $product = $incident->getProduct();
        if (array_key_exists('aiSystemId', $data)) {
            $aiSystem = null === $data['aiSystemId'] ? null : $this->aiSystems->findOneVisibleTo((int) $data['aiSystemId'], $actor);
            if (null !== $data['aiSystemId'] && !$aiSystem instanceof AiSystem) {
                throw new \InvalidArgumentException('Système d’IA introuvable.');
            }
        }
        if (array_key_exists('productId', $data)) {
            $product = null === $data['productId'] ? null : $this->products->findOneVisibleTo((int) $data['productId'], $actor);
            if (null !== $data['productId'] && !$product instanceof DigitalProduct) {
                throw new \InvalidArgumentException('Produit introuvable.');
            }
        }
        $incident->link($aiSystem, $product);
    }

    /** @return array<string, mixed> */
    private function item(RegulatoryIncident $incident, \DateTimeImmutable $now): array
    {
        $facts = $incident->deadlineFacts();
        $steps = $this->deadlines->steps($incident->getRegime(), $incident->getAwareAt(), $incident->isApplicable(), $facts, $incident->completedDates(), $now);
        $owner = $incident->getOwner();

        return [
            'id' => $incident->getId(), 'regime' => $incident->getRegime(), 'title' => $incident->getTitle(), 'description' => $incident->getDescription(),
            'awareAt' => $incident->getAwareAt()->format(DATE_ATOM), 'classifiedAt' => $facts['classifiedAt']?->format(DATE_ATOM), 'correctiveAvailableAt' => $facts['correctiveAvailableAt']?->format(DATE_ATOM),
            'widespread' => $facts['widespread'], 'criticalInfrastructure' => $facts['criticalInfrastructure'], 'death' => $facts['death'],
            'applicable' => $incident->isApplicable(), 'status' => $incident->getStatus(),
            'owner' => ['id' => $owner->getId(), 'name' => trim($owner->getFirstName().' '.$owner->getLastName())],
            'aiSystem' => null === $incident->getAiSystem() ? null : ['id' => $incident->getAiSystem()->getId(), 'name' => $incident->getAiSystem()->getName()],
            'product' => null === $incident->getProduct() ? null : ['id' => $incident->getProduct()->getId(), 'name' => $incident->getProduct()->getName(), 'version' => $incident->getProduct()->getVersion()],
            'steps' => $steps, 'worstStatus' => $this->deadlines->worstStatus($steps), 'updatedAt' => $incident->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    private function requiredDate(mixed $value, string $label): \DateTimeImmutable
    {
        return $this->optionalDate($value, $label) ?? throw new \InvalidArgumentException(sprintf('%s est obligatoire.', $label));
    }

    /** N'accepte qu'une date ISO 8601 explicite : les expressions relatives ("tomorrow") sont refusées. */
    private function optionalDate(mixed $value, string $label): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s doit être une date ISO 8601.', $label));
        }
        foreach ([DATE_ATOM, 'Y-m-d\TH:i:sp', 'Y-m-d\TH:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
            if ($date instanceof \DateTimeImmutable && 0 === ($date::getLastErrors()['warning_count'] ?? 0) && 0 === ($date::getLastErrors()['error_count'] ?? 0)) {
                return $date;
            }
        }
        throw new \InvalidArgumentException(sprintf('%s doit être une date ISO 8601.', $label));
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_INPUT', 'message' => $message], 422);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Dossier introuvable.'], 404);
    }
}
