<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Application\NotificationService;
use App\Entity\RiskAcceptance;
use App\Entity\RiskReview;
use App\Entity\RiskReviewCampaign;
use App\Entity\User;
use App\Repository\ActionPlanRepository;
use App\Repository\RiskAcceptanceRepository;
use App\Repository\RiskGovernancePolicyRepository;
use App\Repository\RiskReviewCampaignRepository;
use App\Repository\RiskScenarioRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/risk-governance')]
final readonly class RiskWorkflowController
{
    public function __construct(private CurrentUser $currentUser, private RiskScenarioRepository $risks, private RiskAcceptanceRepository $acceptances, private RiskGovernancePolicyRepository $policies, private RiskReviewCampaignRepository $campaigns, private ActionPlanRepository $actions, private UserRepository $users, private NotificationService $notifications, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/acceptances', methods: ['GET'])]
    public function acceptances(): JsonResponse
    {
        return new JsonResponse(array_map($this->acceptance(...), $this->acceptances->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/risks/{riskId<\d+>}/acceptances', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function requestAcceptance(int $riskId, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $risk = $this->risks->findOneVisibleTo($riskId, $actor);
        $input = $request->toArray();
        $justification = trim((string) ($input['justification'] ?? ''));
        $authority = trim((string) ($input['authority'] ?? ''));
        $expiresAt = $this->date($input['expiresAt'] ?? null);
        if (null === $risk || '' === $justification || '' === $authority || null === $expiresAt || $expiresAt <= new \DateTimeImmutable() || null !== $risk->getId() && $this->acceptances->hasActiveForRisk($risk->getId())) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => null === $risk ? 'Risque introuvable.' : 'Justification, autorité et expiration future sont requises, sans demande active existante.'], null === $risk ? 404 : 422);
        }
        $acceptance = new RiskAcceptance($risk, $actor, $justification, $authority, $expiresAt, isset($input['evidenceReference']) ? (string) $input['evidenceReference'] : null);
        $risk->setStatus('IN_REVIEW')->setTreatmentDecision('ACCEPT');
        $this->entityManager->persist($acceptance);
        $admins = $this->users->findBy(['organization' => $actor->getOrganization(), 'status' => User::STATUS_ACTIVE]);
        foreach ($admins as $admin) {
            if (array_intersect([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], $admin->getRoles())) {
                $this->notifications->notify($admin, 'RISK_ACCEPTANCE_REQUIRED', 'Acceptation de risque à décider', sprintf('Le risque « %s » nécessite une décision formelle.', $risk->getTitle()), '/risks');
            }
        }
        $this->entityManager->flush();

        return new JsonResponse($this->acceptance($acceptance), 201);
    }

    #[Route('/acceptances/{id<\d+>}/decision', methods: ['POST'])] #[IsGranted(User::ROLE_ADMIN)]
    public function decideAcceptance(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $acceptance = $this->acceptances->findOneVisibleTo($id, $actor);
        $input = $request->toArray();
        try {
            if (null === $acceptance) {
                return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Demande introuvable.'], 404);
            }
            $acceptance->decide((string) ($input['status'] ?? ''), $actor, isset($input['comment']) ? (string) $input['comment'] : null);
            $this->notifications->notify($acceptance->getRequestedBy(), 'RISK_ACCEPTANCE_DECIDED', 'Décision d’acceptation de risque', sprintf('La demande pour « %s » est %s.', $acceptance->getRisk()->getTitle(), 'APPROVED' === $acceptance->getStoredStatus() ? 'approuvée' : 'refusée'), '/risks');
            $this->entityManager->flush();
        } catch (\LogicException $error) {
            return new JsonResponse(['code' => 'INVALID_TRANSITION', 'message' => $error->getMessage()], 409);
        }

        return new JsonResponse($this->acceptance($acceptance));
    }

    #[Route('/campaigns', methods: ['GET'])]
    public function campaigns(): JsonResponse
    {
        return new JsonResponse(array_map($this->campaign(...), $this->campaigns->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/campaigns', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function createCampaign(Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $input = $request->toArray();
        $reviewerId = filter_var($input['reviewerId'] ?? null, FILTER_VALIDATE_INT);
        $reviewer = false === $reviewerId ? null : $this->users->findOneVisibleTo($reviewerId, $actor);
        $riskIds = array_values(array_unique(array_filter(array_map('intval', is_array($input['riskIds'] ?? null) ? $input['riskIds'] : []), static fn (int $id): bool => $id > 0)));
        $risks = array_values(array_filter(array_map(fn (int $id) => $this->risks->findOneVisibleTo($id, $actor), $riskIds)));
        $startsAt = $this->date($input['startsAt'] ?? null);
        $dueAt = $this->date($input['dueAt'] ?? null);
        $title = trim((string) ($input['title'] ?? ''));
        if ('' === $title || null === $reviewer || [] === $risks || count($risks) !== count($riskIds) || null === $startsAt || null === $dueAt || $dueAt <= $startsAt) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => 'Titre, réviseur, risques et période cohérente sont requis.'], 422);
        }
        $campaign = new RiskReviewCampaign($actor->getOrganization(), $title, $startsAt, $dueAt, $actor);
        $campaign->configure(isset($input['description']) ? (string) $input['description'] : null, (string) ($input['status'] ?? 'DRAFT'));
        if (!in_array($campaign->getStatus(), RiskReviewCampaign::STATUSES, true)) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => 'Statut de campagne invalide.'], 422);
        }
        foreach ($risks as $risk) {
            $campaign->addReview(new RiskReview($campaign, $risk, $reviewer));
        }
        $this->entityManager->persist($campaign);
        if ('ACTIVE' === $campaign->getStatus()) {
            $this->notifications->notify($reviewer, 'RISK_REVIEW_CAMPAIGN', 'Campagne de revue affectée', sprintf('La campagne « %s » contient %d risque(s) à revoir.', $campaign->getTitle(), count($risks)), '/risks');
        }
        $this->entityManager->flush();

        return new JsonResponse($this->campaign($campaign), 201);
    }

    #[Route('/reviews/{id<\d+>}/complete', methods: ['POST'])]
    public function completeReview(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $review = $this->entityManager->getRepository(RiskReview::class)->find($id);
        if (!$review instanceof RiskReview || $review->getCampaign()->getOrganization() !== $actor->getOrganization() || $review->getReviewer() !== $actor && !array_intersect([User::ROLE_ADMIN, User::ROLE_RISK_MANAGER], $actor->getRoles())) {
            return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Revue introuvable.'], 404);
        }
        $input = $request->toArray();
        $score = filter_var($input['reviewedScore'] ?? null, FILTER_VALIDATE_INT);
        if (false === $score || $score < 1 || $score > 25) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => 'Le score revu doit être compris entre 1 et 25.'], 422);
        }
        $review->complete($score, isset($input['comment']) ? (string) $input['comment'] : null);
        $review->getCampaign()->completeIfReviewed();
        $this->entityManager->flush();

        return new JsonResponse($this->review($review));
    }

    #[Route('/recommendations', methods: ['GET'])]
    public function recommendations(): JsonResponse
    {
        $actor = $this->currentUser->get();
        $items = [];
        foreach ($this->risks->findVisibleTo($actor) as $risk) {
            $policy = $this->policies->findForRisk($actor, $risk->getScope()->getName(), $risk->getFamily());
            $position = $policy?->position($risk->getResidualRiskScore()) ?? 'UNDEFINED';
            $decision = match ($position) {
                'WITHIN_APPETITE' => 'ACCEPT', 'TOLERATED' => 'MONITOR', 'ABOVE_TOLERANCE' => 'REDUCE', 'ABOVE_CAPACITY' => 'AVOID_OR_TRANSFER', default => 'DEFINE_POLICY',
            };
            $plans = $this->actions->findForRisk((int) $risk->getId(), $actor);
            $cost = array_sum(array_map(static fn ($plan): float => (float) ($plan->getEstimatedCost() ?? 0), $plans));
            $effort = array_sum(array_map(static fn ($plan): float => (float) ($plan->getEstimatedEffortDays() ?? 0), $plans));
            $reduction = array_sum(array_map(static fn ($plan): int => $plan->getExpectedRiskReduction() ?? 0, $plans));
            $coverageGap = max(0, $risk->getResidualRiskScore() - $reduction - ($policy?->getToleranceScore() ?? 0));
            $items[] = ['riskId' => $risk->getId(), 'title' => $risk->getTitle(), 'family' => $risk->getFamily(), 'strategic' => $risk->isStrategic(), 'method' => $risk->getAnalysisMethod(), 'residualScore' => $risk->getResidualRiskScore(), 'position' => $position, 'recommendedDecision' => $decision, 'treatment' => ['estimatedCost' => round($cost, 2), 'estimatedEffortDays' => round($effort, 2), 'expectedReduction' => $reduction, 'coverageGap' => $coverageGap, 'reductionPerThousand' => $cost > 0 ? round($reduction / ($cost / 1000), 2) : null], 'priority' => max(1, min(100, $risk->getResidualRiskScore() * ($risk->isStrategic() ? 4 : 3) + $coverageGap * 2))];
        }
        usort($items, static fn (array $left, array $right): int => $right['priority'] <=> $left['priority']);

        return new JsonResponse($items);
    }

    #[Route('/portfolio', methods: ['GET'])]
    public function portfolio(): JsonResponse
    {
        $actor = $this->currentUser->get();
        $families = [];
        foreach ($this->risks->findVisibleTo($actor) as $risk) {
            $family = $risk->getFamily();
            $families[$family] ??= ['family' => $family, 'strategic' => 0, 'operational' => 0, 'scores' => [], 'aboveTolerance' => 0];
            ++$families[$family][$risk->isStrategic() ? 'strategic' : 'operational'];
            $families[$family]['scores'][] = $risk->getResidualRiskScore();
            $policy = $this->policies->findForRisk($actor, $risk->getScope()->getName(), $family);
            if (null !== $policy && $risk->getResidualRiskScore() > $policy->getToleranceScore()) {
                ++$families[$family]['aboveTolerance'];
            }
        }
        $portfolio = array_map(static function (array $family): array {
            $scores = $family['scores'];
            unset($family['scores']);
            $family['averageResidualScore'] = round(array_sum($scores) / count($scores), 1);
            $family['maximumResidualScore'] = max($scores);
            $family['total'] = count($scores);

            return $family;
        }, array_values($families));
        usort($portfolio, static fn (array $left, array $right): int => $right['maximumResidualScore'] <=> $left['maximumResidualScore']);

        return new JsonResponse($portfolio);
    }

    /** @return array<string, mixed> */
    private function acceptance(RiskAcceptance $item): array
    {
        $requester = $item->getRequestedBy();
        $decider = $item->getDecidedBy();

        return ['id' => $item->getId(), 'risk' => ['id' => $item->getRisk()->getId(), 'title' => $item->getRisk()->getTitle(), 'residualScore' => $item->getRisk()->getResidualRiskScore()], 'requestedBy' => ['id' => $requester->getId(), 'name' => $requester->getFirstName().' '.$requester->getLastName()], 'decidedBy' => null === $decider ? null : ['id' => $decider->getId(), 'name' => $decider->getFirstName().' '.$decider->getLastName()], 'justification' => $item->getJustification(), 'authority' => $item->getAuthority(), 'status' => $item->getStatus(), 'expiresAt' => $item->getExpiresAt()->format(DATE_ATOM), 'decidedAt' => $item->getDecidedAt()?->format(DATE_ATOM), 'decisionComment' => $item->getDecisionComment(), 'evidenceReference' => $item->getEvidenceReference(), 'createdAt' => $item->getCreatedAt()->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function campaign(RiskReviewCampaign $item): array
    {
        return ['id' => $item->getId(), 'title' => $item->getTitle(), 'description' => $item->getDescription(), 'status' => $item->getStatus(), 'startsAt' => $item->getStartsAt()->format(DATE_ATOM), 'dueAt' => $item->getDueAt()->format(DATE_ATOM), 'coordinator' => ['id' => $item->getCoordinator()->getId(), 'name' => $item->getCoordinator()->getFirstName().' '.$item->getCoordinator()->getLastName()], 'progress' => ['completed' => count($item->getReviews()->filter(static fn (RiskReview $review): bool => 'COMPLETED' === $review->getStatus())), 'total' => $item->getReviews()->count()], 'reviews' => array_map($this->review(...), $item->getReviews()->toArray()), 'createdAt' => $item->getCreatedAt()->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function review(RiskReview $item): array
    {
        return ['id' => $item->getId(), 'risk' => ['id' => $item->getRisk()->getId(), 'title' => $item->getRisk()->getTitle()], 'reviewer' => ['id' => $item->getReviewer()->getId(), 'name' => $item->getReviewer()->getFirstName().' '.$item->getReviewer()->getLastName()], 'status' => $item->getStatus(), 'baselineScore' => $item->getBaselineScore(), 'reviewedScore' => $item->getReviewedScore(), 'delta' => null === $item->getReviewedScore() ? null : $item->getReviewedScore() - $item->getBaselineScore(), 'comment' => $item->getComment(), 'completedAt' => $item->getCompletedAt()?->format(DATE_ATOM)];
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
