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
        if (!is_int($data['reviewerId'] ?? null) || $data['reviewerId'] < 1 || $data['reviewerId'] > 2147483647) {
            return $this->invalid('Évaluateur invalide.');
        }
        $reviewer = $this->users->findOneVisibleTo($data['reviewerId'], $this->currentUser->get());
        if (null === $reviewer) {
            return $this->invalid('Évaluateur invalide.');
        }
        $version = array_key_exists('version', $data) ? $data['version'] : 1;
        if (!is_string($data['title'] ?? null) || '' === trim($data['title']) || mb_strlen(trim($data['title'])) > 200 || !is_int($version) || $version < 1 || $version > 2147483647) {
            return $this->invalid('Le titre et la version du questionnaire sont invalides.');
        }
        if (!is_array($data['questions'] ?? null) || !array_is_list($data['questions']) || [] === $data['questions'] || count($data['questions']) > 100) {
            return $this->invalid('Le questionnaire doit contenir entre 1 et 100 questions.');
        }
        $questions = [];
        $questionIds = [];
        foreach ($data['questions'] as $question) {
            $weight = is_array($question) && array_key_exists('weight', $question) ? $question['weight'] : 1;
            if (!is_array($question) || !is_string($question['id'] ?? null) || '' === trim($question['id']) || mb_strlen(trim($question['id'])) > 100 || !is_string($question['label'] ?? null) || '' === trim($question['label']) || mb_strlen(trim($question['label'])) > 2000 || !is_int($weight) || $weight < 1 || $weight > 100) {
                return $this->invalid('Questionnaire invalide.');
            }
            $questionId = trim($question['id']);
            if (in_array($questionId, $questionIds, true)) {
                return $this->invalid('Les identifiants des questions doivent être uniques.');
            }
            $questionIds[] = $questionId;
            $questions[] = ['id' => $questionId, 'label' => trim($question['label']), 'weight' => $weight];
        }
        try {
            $expiresAt = $this->assessmentExpiry($data['expiresAt'] ?? null);
            $assessment = new SupplierAssessment($thirdParty, $reviewer, $data['title'], $version, $questions, $expiresAt);
            $this->entityManager->persist($assessment);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->assessmentResponse($assessment, true), 201);
    }

    #[Route('/api/supplier-assessments/{id<\d+>}', methods: ['GET'])]
    public function assessmentDetails(int $id): JsonResponse
    {
        $assessment = $this->assessment($id);
        if (null === $assessment) {
            return $this->notFound();
        }

        return new JsonResponse([
            ...$this->assessmentResponse($assessment),
            'questions' => $assessment->getQuestions(),
            'responses' => $assessment->getResponses(),
            'evidence' => $assessment->getEvidence(),
        ]);
    }

    #[Route('/api/supplier-assessments/{id<\d+>}/review', methods: ['POST'])]
    public function reviewAssessment(int $id, Request $request): JsonResponse
    {
        $assessment = $this->assessment($id);
        if (null === $assessment || !$this->canManage()) {
            return null === $assessment ? $this->notFound() : $this->forbidden();
        } $data = $request->toArray();
        if (!is_int($data['score'] ?? null) || !is_string($data['comment'] ?? null)) {
            return $this->invalid('Le score doit être un entier et le commentaire un texte.');
        }
        try {
            $assessment->review($data['score'], $data['comment']);
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
        if (!is_array($data['responses'] ?? null)) {
            return $this->invalid('Les réponses doivent être associées aux identifiants des questions.');
        }
        $questionIds = array_column($assessment->getQuestions(), 'id');
        foreach ($data['responses'] as $id => $answer) {
            if (!in_array((string) $id, $questionIds, true) || (!is_bool($answer) && (!is_string($answer) || '' === trim($answer) || mb_strlen($answer) > 4000))) {
                return $this->invalid('Chaque réponse doit être un booléen ou un texte non vide de 4 000 caractères maximum.');
            }
        }
        $evidence = array_key_exists('evidence', $data) ? $data['evidence'] : [];
        if (!is_array($evidence) || !array_is_list($evidence) || count($evidence) > 10) {
            return $this->invalid('Les justificatifs doivent être une liste de références textuelles.');
        }
        foreach ($evidence as $reference) {
            if (!is_string($reference) || '' === trim($reference) || mb_strlen($reference) > 500 || str_contains($reference, '<') || str_contains($reference, '>')) {
                return $this->invalid('Chaque justificatif doit être une référence textuelle non vide de 500 caractères maximum, sans HTML.');
            }
        }
        try {
            $assessment->submit($data['responses'], array_map(trim(...), $evidence));
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
            $contractEndsAt = $this->dateOnly($data['contractEndsAt'] ?? null, 'La date de fin de contrat');
            $nextAssessmentAt = $this->dateOnly($data['nextAssessmentAt'] ?? null, 'La date de prochaine évaluation');
            $item ??= new ThirdParty($actor->getOrganization(), $owner, (string) ($data['name'] ?? ''), (string) ($data['criticality'] ?? 'MEDIUM'));
            $item->update((string) ($data['name'] ?? ''), isset($data['contactEmail']) ? (string) $data['contactEmail'] : null, isset($data['services']) ? (string) $data['services'] : null, $this->strings((array) ($data['dataCategories'] ?? [])), (string) ($data['criticality'] ?? 'MEDIUM'), (string) ($data['status'] ?? 'ACTIVE'), isset($data['contractReference']) ? (string) $data['contractReference'] : null, isset($data['sla']) ? (string) $data['sla'] : null, isset($data['dependencies']) ? (string) $data['dependencies'] : null, isset($data['exitPlan']) ? (string) $data['exitPlan'] : null, $contractEndsAt, $nextAssessmentAt, $owner);
            $item->assessRisk($this->strings((array) ($data['certifications'] ?? [])), isset($data['riskSummary']) ? (string) $data['riskSummary'] : null, isset($data['compensatingMeasures']) ? (string) $data['compensatingMeasures'] : null);
            $this->entityManager->persist($item);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }

        return new JsonResponse($this->thirdPartyResponse($item), $created ? 201 : 200);
    }

    private function dateOnly(mixed $value, string $label): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new \InvalidArgumentException($label.' doit respecter le format AAAA-MM-JJ.');
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException($label.' est invalide.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($label.' est invalide.');
        }

        return $date;
    }

    private function assessmentExpiry(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('La date d’expiration doit être une date ISO 8601 valide.');
        }
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value, $matches) || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) || (int) $matches[4] > 23 || (int) $matches[5] > 59 || (int) $matches[6] > 59) {
            throw new \InvalidArgumentException('La date d’expiration doit être une date ISO 8601 valide.');
        }

        return new \DateTimeImmutable($value);
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
