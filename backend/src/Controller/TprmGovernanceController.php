<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\ApiPagination;
use App\Application\CurrentUser;
use App\Entity\OperationalRecord;
use App\Entity\SupplierAssessment;
use App\Entity\ThirdParty;
use App\Entity\User;
use App\Repository\OperationalRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/tprm')]
final readonly class TprmGovernanceController
{
    public function __construct(private CurrentUser $currentUser, private OperationalRecordRepository $records, private EntityManagerInterface $entityManager) {}

    #[Route('/campaigns', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function campaign(Request $request): JsonResponse
    {
        $data = $request->toArray(); $actor = $this->currentUser->get(); $thirdPartyIds = array_values(array_unique(array_map('intval', (array) ($data['thirdPartyIds'] ?? []))));
        $thirdParties = $this->entityManager->getRepository(ThirdParty::class)->findBy(['id' => $thirdPartyIds, 'organization' => $actor->getOrganization()]);
        $questionsByTier = (array) ($data['questionsByTier'] ?? []); $title = trim((string) ($data['title'] ?? ''));
        try { $expiresAt = new \DateTimeImmutable((string) ($data['expiresAt'] ?? '')); } catch (\Exception) { return $this->invalid('Date d’expiration invalide.'); }
        if ('' === $title || [] === $thirdPartyIds || count($thirdParties) !== count($thirdPartyIds) || $expiresAt <= new \DateTimeImmutable()) return $this->invalid('Campagne TPRM invalide ou hors tenant.');
        $assessmentIds = []; $segments = [];
        foreach ($thirdParties as $thirdParty) {
            $tier = match ($thirdParty->getCriticality()) { 'CRITICAL' => 'DEEP', 'HIGH' => 'STANDARD', default => 'LIGHT' };
            $questions = (array) ($questionsByTier[$tier] ?? []); if ([] === $questions) return $this->invalid('Chaque segment utilisé doit avoir un questionnaire.');
            $normalized = []; foreach ($questions as $index => $question) { if (!is_array($question) || '' === trim((string) ($question['label'] ?? ''))) return $this->invalid('Question TPRM invalide.'); $normalized[] = ['id' => (string) ($question['id'] ?? $tier.'-'.($index + 1)), 'label' => trim((string) $question['label']), 'weight' => max(1, min(100, (int) ($question['weight'] ?? 1)))]; }
            $assessment = new SupplierAssessment($thirdParty, $actor, $title.' — '.$thirdParty->getName(), (int) ($data['questionnaireVersion'] ?? 1), $normalized, $expiresAt); $this->entityManager->persist($assessment); $this->entityManager->flush(); $assessmentIds[] = $assessment->getId(); $segments[$tier] = ($segments[$tier] ?? 0) + 1;
        }
        $details = ['thirdPartyIds' => $thirdPartyIds, 'assessmentIds' => $assessmentIds, 'segments' => $segments, 'questionnaireVersion' => (int) ($data['questionnaireVersion'] ?? 1), 'expiresAt' => $expiresAt->format(DATE_ATOM), 'reminderDays' => array_values(array_unique(array_map('intval', (array) ($data['reminderDays'] ?? [30, 14, 7])))), 'createdBy' => $actor->getEmail()];
        $record = new OperationalRecord($actor->getOrganization(), 'TPRM_CAMPAIGN', $title, $details); $record->update($title, 'ACTIVE', $details, $actor, $expiresAt); $this->entityManager->persist($record); $this->entityManager->flush(); return new JsonResponse($this->serialize($record), 201);
    }

    #[Route('/attestations', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function attestation(Request $request): JsonResponse
    {
        $data = $request->toArray(); $actor = $this->currentUser->get(); $thirdParty = $this->entityManager->getRepository(ThirdParty::class)->findOneBy(['id' => (int) ($data['thirdPartyId'] ?? 0), 'organization' => $actor->getOrganization()]); $sha = strtolower((string) ($data['sha256'] ?? ''));
        if (!$thirdParty instanceof ThirdParty || '' === trim((string) ($data['name'] ?? '')) || 1 !== preg_match('/^[a-f0-9]{64}$/', $sha)) return $this->invalid('Attestation invalide ou hors tenant.');
        try { $validUntil = empty($data['validUntil']) ? null : new \DateTimeImmutable((string) $data['validUntil']); } catch (\Exception) { return $this->invalid('Date de validité invalide.'); }
        $details = ['thirdPartyId' => $thirdParty->getId(), 'thirdPartyName' => $thirdParty->getName(), 'type' => strtoupper((string) ($data['type'] ?? 'OTHER')), 'issuer' => trim((string) ($data['issuer'] ?? '')), 'sourceReference' => trim((string) ($data['sourceReference'] ?? '')), 'sha256' => $sha, 'validFrom' => $data['validFrom'] ?? null, 'validUntil' => $validUntil?->format('Y-m-d'), 'reviewStatus' => 'APPROVED'];
        $record = new OperationalRecord($actor->getOrganization(), 'TPRM_ATTESTATION', (string) $data['name'], $details); $record->update($record->getTitle(), 'ACTIVE', $details, $actor, $validUntil); $this->entityManager->persist($record); $this->entityManager->flush(); return new JsonResponse($this->serialize($record), 201);
    }

    #[Route('/governance', methods: ['GET'])]
    public function governance(Request $request): JsonResponse
    {
        $actor = $this->currentUser->get(); $pagination = ApiPagination::fromRequest($request); $all = [...$this->records->findForOrganization($actor->getOrganization(), 'TPRM_CAMPAIGN'), ...$this->records->findForOrganization($actor->getOrganization(), 'TPRM_ATTESTATION')]; usort($all, static fn (OperationalRecord $a, OperationalRecord $b): int => $b->getUpdatedAt() <=> $a->getUpdatedAt()); $total = count($all);
        $alerts = []; $today = new \DateTimeImmutable('today'); foreach ($this->entityManager->getRepository(ThirdParty::class)->findBy(['organization' => $actor->getOrganization()]) as $thirdParty) { if (null !== $thirdParty->getNextAssessmentAt() && $thirdParty->getNextAssessmentAt() < $today) $alerts[] = ['thirdPartyId' => $thirdParty->getId(), 'code' => 'REASSESSMENT_OVERDUE']; if ('CRITICAL' === $thirdParty->getCriticality() && null === $thirdParty->getExitPlan()) $alerts[] = ['thirdPartyId' => $thirdParty->getId(), 'code' => 'EXIT_PLAN_MISSING']; if (null !== $thirdParty->getContractEndsAt() && $thirdParty->getContractEndsAt() <= $today->modify('+90 days')) $alerts[] = ['thirdPartyId' => $thirdParty->getId(), 'code' => 'CONTRACT_EXPIRING']; }
        return new JsonResponse([...ApiPagination::response(array_map($this->serialize(...), array_slice($all, ($pagination['page'] - 1) * $pagination['limit'], $pagination['limit'])), $pagination['page'], $pagination['limit'], $total), 'alerts' => $alerts]);
    }

    /** @return array<string, mixed> */ private function serialize(OperationalRecord $record): array { return ['id' => $record->getId(), 'type' => $record->getType(), 'title' => $record->getTitle(), 'status' => $record->getStatus(), 'details' => $record->getDetails(), 'dueAt' => $record->getDueAt()?->format(DATE_ATOM), 'updatedAt' => $record->getUpdatedAt()->format(DATE_ATOM)]; }
    private function invalid(string $message): JsonResponse { return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => $message], 422); }
}
