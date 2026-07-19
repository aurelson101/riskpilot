<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Api\Dto\ActionPlanInput;
use App\Api\JsonInputMapper;
use App\Application\CurrentUser;
use App\Application\NotificationService;
use App\Entity\ActionComment;
use App\Entity\ActionPlan;
use App\Entity\User;
use App\Repository\ActionPlanRepository;
use App\Repository\RiskScenarioRepository;
use App\Repository\SecurityControlRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/actions')]
final readonly class ActionPlanController
{
    public function __construct(
        private ActionPlanRepository $actions, private RiskScenarioRepository $risks,
        private SecurityControlRepository $controls, private UserRepository $users,
        private CurrentUser $currentUser, private EntityManagerInterface $entityManager,
        private JsonInputMapper $mapper, private ApiResponseFactory $responses,
        private NotificationService $notifications,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->responses->actionPlan(...), $this->actions->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $item = $this->actions->findOneVisibleTo($id, $this->currentUser->get());

        return null === $item ? $this->notFound() : new JsonResponse($this->responses->actionPlan($item));
    }

    #[Route('', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        return $this->save(null, $request);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])] #[IsGranted(User::ROLE_VIEWER)]
    public function update(int $id, Request $request): JsonResponse
    {
        $item = $this->actions->findOneVisibleTo($id, $this->currentUser->get());

        return null === $item ? $this->notFound() : $this->save($item, $request);
    }

    #[Route('/{id<\d+>}/comments', methods: ['GET'])]
    public function comments(int $id): JsonResponse
    {
        $action = $this->actions->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $action) {
            return $this->notFound();
        }
        $items = $this->entityManager->getRepository(ActionComment::class)->findBy(['actionPlan' => $action], ['createdAt' => 'ASC']);

        return new JsonResponse(array_map($this->responses->actionComment(...), $items));
    }

    #[Route('/{id<\d+>}/comments', methods: ['POST'])]
    public function addComment(int $id, Request $request): JsonResponse
    {
        $action = $this->actions->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $action) {
            return $this->notFound();
        }
        $message = trim((string) ($request->toArray()['message'] ?? ''));
        if ('' === $message || mb_strlen($message) > 5000) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => 'Le commentaire est obligatoire et limité à 5000 caractères.'], 422);
        }
        $comment = new ActionComment($action, $this->currentUser->get(), $message);
        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        return new JsonResponse($this->responses->actionComment($comment), 201);
    }

    private function save(?ActionPlan $item, Request $request): JsonResponse
    {
        [$input, $violations] = $this->mapper->map($request, ActionPlanInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }
        $actor = $this->currentUser->get();
        $risk = null === $input->relatedRiskId ? null : $this->risks->findOneVisibleTo($input->relatedRiskId, $actor);
        $control = null === $input->relatedControlId ? null : $this->controls->findOneVisibleTo($input->relatedControlId, $actor);
        $owner = null === $input->ownerId ? null : $this->users->findOneVisibleTo($input->ownerId, $actor);
        if (null === $risk || null === $owner || (null !== $input->relatedControlId && null === $control)) {
            return new JsonResponse(['code' => 'INVALID_RELATION', 'message' => 'Une ou plusieurs relations sont invalides.'], 422);
        }
        $dueDate = new \DateTimeImmutable((string) $input->dueDate);
        $startDate = null === $input->startDate ? null : new \DateTimeImmutable($input->startDate);
        if (null !== $startDate && $dueDate < $startDate) {
            return new JsonResponse(['code' => 'INVALID_DATES', 'message' => 'L’échéance doit être postérieure au début.'], 422);
        }
        $created = null === $item;
        $previousOwner = $item?->getOwner();
        $item ??= new ActionPlan($input->title, $actor->getOrganization(), $risk, $owner, $dueDate);
        if (!$created && $item->getOwner() !== $actor && !array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_RISK_MANAGER], $actor->getRoles())) {
            return new JsonResponse(['code' => 'FORBIDDEN', 'message' => 'Seul le responsable ou un gestionnaire peut modifier cette action.'], 403);
        }
        $item->setTitle($input->title)->setDescription($input->description)->setRelatedRisk($risk)->setRelatedControl($control)->setOwner($owner)->setPriority($input->priority)->setStatus($input->status)->setStartDate($startDate)->setDueDate($dueDate)->setCompletionDate(null === $input->completionDate ? null : new \DateTimeImmutable($input->completionDate))->setProgress($input->progress)->setEstimatedCost(null === $input->estimatedCost ? null : number_format($input->estimatedCost, 2, '.', ''))->setActualCost(null === $input->actualCost ? null : number_format($input->actualCost, 2, '.', ''))->setExpectedRiskReduction($input->expectedRiskReduction)->setEvidence($input->evidence);
        $this->entityManager->persist($item);
        if ($created || $previousOwner !== $owner) {
            $this->notifications->notify($owner, $created ? 'ACTION_ASSIGNED' : 'ACTION_OWNER_CHANGED', $created ? 'Nouvelle action affectée' : 'Action réaffectée', sprintf('L’action « %s » vous est affectée avec une échéance au %s.', $item->getTitle(), $dueDate->format('d/m/Y')), '/actions');
        }
        $this->entityManager->flush();

        return new JsonResponse($this->responses->actionPlan($item), $created ? 201 : 200);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Plan d’action introuvable.'], 404);
    }
}
