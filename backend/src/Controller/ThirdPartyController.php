<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\SupplierAssessment;
use App\Entity\ThirdParty;
use App\Entity\User;
use App\Repository\ThirdPartyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ThirdPartyController
{
    public function __construct(private ThirdPartyRepository $thirdParties, private UserRepository $users, private CurrentUser $currentUser, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/api/third-parties', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->thirdPartyResponse(...), $this->thirdParties->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/api/third-parties', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->save(null, $request);
    }

    #[Route('/api/third-parties/{id<\d+>}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $item = $this->thirdParties->findOneVisibleTo($id, $this->currentUser->get());

        return null === $item ? $this->notFound() : $this->save($item, $request);
    }

    #[Route('/api/third-parties/{id<\d+>}/assessments', methods: ['POST'])]
    public function createAssessment(int $id, Request $request): JsonResponse
    {
        $thirdParty = $this->thirdParties->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $thirdParty || !$this->canManage()) {
            return null === $thirdParty ? $this->notFound() : $this->forbidden();
        } $data = $request->toArray();
        $reviewer = $this->users->findOneVisibleTo((int) ($data['reviewerId'] ?? 0), $this->currentUser->get());
        if (null === $reviewer) {
            return $this->invalid('Évaluateur invalide.');
        }
        $questions = [];
        foreach ((array) ($data['questions'] ?? []) as $question) {
            if (!is_array($question) || '' === trim((string) ($question['id'] ?? '')) || '' === trim((string) ($question['label'] ?? ''))) {
                return $this->invalid('Questionnaire invalide.');
            } $questions[] = ['id' => (string) $question['id'], 'label' => (string) $question['label'], 'weight' => max(1, (int) ($question['weight'] ?? 1))];
        }
        try {
            $assessment = new SupplierAssessment($thirdParty, $reviewer, (string) ($data['title'] ?? ''), (int) ($data['version'] ?? 1), $questions, new \DateTimeImmutable((string) ($data['expiresAt'] ?? 'now')));
            $this->entityManager->persist($assessment);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->assessmentResponse($assessment, true), 201);
    }

    #[Route('/api/supplier-assessments/{id<\d+>}/review', methods: ['POST'])]
    public function reviewAssessment(int $id, Request $request): JsonResponse
    {
        $assessment = $this->assessment($id);
        if (null === $assessment || !$this->canManage()) {
            return null === $assessment ? $this->notFound() : $this->forbidden();
        } $data = $request->toArray();
        try {
            $assessment->review((int) ($data['score'] ?? -1), (string) ($data['comment'] ?? ''));
            $this->entityManager->flush();
        } catch (\LogicException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->assessmentResponse($assessment));
    }

    #[Route('/api/public/supplier-assessments/{token}', methods: ['GET'])]
    public function publicForm(string $token): JsonResponse
    {
        $assessment = $this->entityManager->getRepository(SupplierAssessment::class)->findOneBy(['publicToken' => $token]);
        if (!$assessment instanceof SupplierAssessment || $assessment->getExpiresAt() < new \DateTimeImmutable()) {
            return $this->notFound();
        }

        return new JsonResponse(['thirdParty' => $assessment->getThirdParty()->getName(), 'title' => $assessment->getTitle(), 'version' => $assessment->getQuestionnaireVersion(), 'questions' => $assessment->getQuestions(), 'expiresAt' => $assessment->getExpiresAt()->format(DATE_ATOM), 'status' => $assessment->getStatus()]);
    }

    #[Route('/api/public/supplier-assessments/{token}', methods: ['POST'])]
    public function publicSubmit(string $token, Request $request): JsonResponse
    {
        $assessment = $this->entityManager->getRepository(SupplierAssessment::class)->findOneBy(['publicToken' => $token]);
        if (!$assessment instanceof SupplierAssessment) {
            return $this->notFound();
        } $data = $request->toArray();
        try {
            $assessment->submit((array) ($data['responses'] ?? []), $this->strings((array) ($data['evidence'] ?? [])));
            $this->entityManager->flush();
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse(['status' => $assessment->getStatus(), 'submittedAt' => $assessment->getSubmittedAt()?->format(DATE_ATOM)]);
    }

    private function save(?ThirdParty $item, Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        } $data = $request->toArray();
        $actor = $this->currentUser->get();
        $owner = $this->users->findOneVisibleTo((int) ($data['ownerId'] ?? 0), $actor);
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        } $created = null === $item;
        try {
            $item ??= new ThirdParty($actor->getOrganization(), $owner, (string) ($data['name'] ?? ''), (string) ($data['criticality'] ?? 'MEDIUM'));
            $item->update((string) ($data['name'] ?? ''), isset($data['contactEmail']) ? (string) $data['contactEmail'] : null, isset($data['services']) ? (string) $data['services'] : null, $this->strings((array) ($data['dataCategories'] ?? [])), (string) ($data['criticality'] ?? 'MEDIUM'), (string) ($data['status'] ?? 'ACTIVE'), isset($data['contractReference']) ? (string) $data['contractReference'] : null, isset($data['sla']) ? (string) $data['sla'] : null, isset($data['dependencies']) ? (string) $data['dependencies'] : null, isset($data['exitPlan']) ? (string) $data['exitPlan'] : null, empty($data['contractEndsAt']) ? null : new \DateTimeImmutable((string) $data['contractEndsAt']), empty($data['nextAssessmentAt']) ? null : new \DateTimeImmutable((string) $data['nextAssessmentAt']), $owner);
            $item->assessRisk($this->strings((array) ($data['certifications'] ?? [])), isset($data['riskSummary']) ? (string) $data['riskSummary'] : null, isset($data['compensatingMeasures']) ? (string) $data['compensatingMeasures'] : null);
            $this->entityManager->persist($item);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->thirdPartyResponse($item), $created ? 201 : 200);
    }

    private function assessment(int $id): ?SupplierAssessment
    {
        $item = $this->entityManager->getRepository(SupplierAssessment::class)->find($id);

        return $item instanceof SupplierAssessment && $item->getThirdParty()->getOrganization() === $this->currentUser->get()->getOrganization() ? $item : null;
    }

    /** @return array<string, mixed> */
    private function thirdPartyResponse(ThirdParty $item): array
    {
        return ['id' => $item->getId(), 'name' => $item->getName(), 'contactEmail' => $item->getContactEmail(), 'services' => $item->getServices(), 'dataCategories' => $item->getDataCategories(), 'criticality' => $item->getCriticality(), 'status' => $item->getStatus(), 'contractReference' => $item->getContractReference(), 'sla' => $item->getSla(), 'dependencies' => $item->getDependencies(), 'exitPlan' => $item->getExitPlan(), 'contractEndsAt' => $item->getContractEndsAt()?->format('Y-m-d'), 'nextAssessmentAt' => $item->getNextAssessmentAt()?->format('Y-m-d'), 'cyberScore' => $item->getCyberScore(), 'certifications' => $item->getCertifications(), 'riskSummary' => $item->getRiskSummary(), 'compensatingMeasures' => $item->getCompensatingMeasures(), 'owner' => ['id' => $item->getOwner()->getId(), 'name' => trim($item->getOwner()->getFirstName().' '.$item->getOwner()->getLastName())], 'assessments' => array_map($this->assessmentResponse(...), $item->getAssessments()->toArray())];
    }

    /** @return array<string, mixed> */
    private function assessmentResponse(SupplierAssessment $item, bool $withToken = false): array
    {
        $response = ['id' => $item->getId(), 'title' => $item->getTitle(), 'version' => $item->getQuestionnaireVersion(), 'reviewer' => ['id' => $item->getReviewer()->getId(), 'name' => trim($item->getReviewer()->getFirstName().' '.$item->getReviewer()->getLastName())], 'status' => $item->getStatus(), 'expiresAt' => $item->getExpiresAt()->format(DATE_ATOM), 'submittedAt' => $item->getSubmittedAt()?->format(DATE_ATOM), 'reviewedAt' => $item->getReviewedAt()?->format(DATE_ATOM), 'score' => $item->getScore(), 'reviewComment' => $item->getReviewComment()];
        if ($withToken) {
            $response['publicToken'] = $item->getPublicToken();
        }

        return $response;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $values)));
    }

    private function canManage(): bool
    {
        return [] !== array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_RISK_MANAGER, User::ROLE_AUDITOR], $this->currentUser->get()->getRoles());
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['code' => 'FORBIDDEN', 'message' => 'Droits insuffisants.'], 403);
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_INPUT', 'message' => $message], 422);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Ressource introuvable ou expirée.'], 404);
    }
}
