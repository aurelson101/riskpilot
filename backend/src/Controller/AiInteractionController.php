<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\AiInteraction;
use App\Entity\User;
use App\Repository\ActionPlanRepository;
use App\Repository\AiInteractionRepository;
use App\Repository\RiskScenarioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Enregistre la décision humaine prise sur une réponse IA (supervision humaine, ISO/IEC 42001). */
#[Route('/api/copilot/interactions')]
final readonly class AiInteractionController
{
    private const TARGETS = ['RISK', 'ACTION'];

    public function __construct(
        private CurrentUser $currentUser,
        private AiInteractionRepository $interactions,
        private RiskScenarioRepository $risks,
        private ActionPlanRepository $actions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id<\d+>}/decision', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function decide(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $interaction = $this->interactions->find($id);
        if (!$interaction instanceof AiInteraction || $interaction->getOrganization() !== $actor->getOrganization()) {
            return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Interaction IA introuvable.'], 404);
        }
        if ('OK' !== $interaction->getStatus() || !in_array($interaction->getWorkflow(), AiInteraction::DRAFT_WORKFLOWS, true)) {
            return new JsonResponse(['code' => 'NOT_A_DRAFT', 'message' => 'Seul un brouillon IA abouti peut recevoir une décision.'], 422);
        }
        if (null !== $interaction->getDecision()) {
            return new JsonResponse(['code' => 'ALREADY_DECIDED', 'message' => 'Une décision a déjà été enregistrée.'], 409);
        }
        $input = $request->toArray();
        $decision = $input['decision'] ?? null;
        $comment = $input['comment'] ?? null;
        $targetType = $input['targetType'] ?? null;
        $targetId = $input['targetId'] ?? null;
        if (!is_string($decision) || !in_array($decision, AiInteraction::DECISIONS, true)
            || (null !== $comment && (!is_string($comment) || mb_strlen($comment) > 2000))
            || (null !== $targetType && (!is_string($targetType) || !in_array($targetType, self::TARGETS, true)))
            || (null !== $targetId && (!is_int($targetId) || $targetId < 1))
            || (null === $targetType) !== (null === $targetId)
            || ('REJECTED' !== $decision && null === $targetType)) {
            return new JsonResponse(['code' => 'INVALID_REQUEST', 'message' => 'Décision, commentaire ou objet créé invalide.'], 422);
        }
        $target = null;
        if (null !== $targetType) {
            $target = 'RISK' === $targetType ? $this->risks->find($targetId) : $this->actions->find($targetId);
            if (null === $target || $target->getOrganization() !== $actor->getOrganization()
                || ('RISK_DRAFT' === $interaction->getWorkflow()) !== ('RISK' === $targetType)) {
                return new JsonResponse(['code' => 'INVALID_TARGET', 'message' => 'L’objet créé est introuvable ou ne correspond pas au brouillon IA.'], 422);
            }
        }
        $interaction->decide($actor, $decision, null === $comment ? null : trim($comment), $targetType, $targetId);
        $target?->markAiAssisted($interaction);
        $this->entityManager->flush();
        $request->attributes->set('_audit_after', [['interactionId' => $interaction->getId(), 'decision' => $decision, 'targetType' => $targetType, 'targetId' => $targetId]]);

        return new JsonResponse(['id' => $interaction->getId(), 'decision' => $decision, 'decidedAt' => $interaction->getDecidedAt()?->format(DATE_ATOM)]);
    }
}
