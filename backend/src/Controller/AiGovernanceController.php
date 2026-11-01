<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\AiImpactAssessment;
use App\Entity\AiSystem;
use App\Entity\User;
use App\Repository\AiImpactAssessmentRepository;
use App\Repository\AiSystemRepository;
use App\Repository\IsmsDocumentRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Gouvernance de l'IA : registre des systèmes, évaluations d'impact et documents obligatoires du SMSI/SMIA. */
#[Route('/api/ai-governance')]
final readonly class AiGovernanceController
{
    /** Informations documentées exigées par ISO/IEC 27001 et 42001 ; la correspondance se fait par mots-clés du titre ou de la catégorie. */
    private const MANDATORY_DOCUMENTS = [
        ['key' => 'SCOPE', 'clause' => '4.3', 'fr' => 'Périmètre du système de management', 'en' => 'Management system scope', 'keywords' => ['périmètre', 'perimetre', 'scope']],
        ['key' => 'POLICY', 'clause' => '5.2', 'fr' => 'Politique de sécurité de l’information / de l’IA', 'en' => 'Information security / AI policy', 'keywords' => ['politique', 'policy']],
        ['key' => 'ROLES', 'clause' => '5.3', 'fr' => 'Rôles et responsabilités', 'en' => 'Roles and responsibilities', 'keywords' => ['rôles', 'roles', 'responsabilit', 'raci']],
        ['key' => 'RISK_METHOD', 'clause' => '6.1.2', 'fr' => 'Méthode d’appréciation des risques', 'en' => 'Risk assessment method', 'keywords' => ['méthode', 'methode', 'method', 'appréciation', 'risk assessment']],
        ['key' => 'TREATMENT_PLAN', 'clause' => '6.1.3', 'fr' => 'Plan de traitement des risques et déclaration d’applicabilité', 'en' => 'Risk treatment plan and statement of applicability', 'keywords' => ['traitement', 'treatment', 'applicabilité', 'applicability', 'soa']],
        ['key' => 'OBJECTIVES', 'clause' => '6.2', 'fr' => 'Objectifs de sécurité de l’information', 'en' => 'Information security objectives', 'keywords' => ['objectif', 'objective']],
        ['key' => 'AI_IMPACT', 'clause' => '6.1.4 (42001)', 'fr' => 'Processus d’évaluation d’impact des systèmes d’IA', 'en' => 'AI system impact assessment process', 'keywords' => ['impact', 'aia']],
        ['key' => 'AUDIT_PROGRAM', 'clause' => '9.2', 'fr' => 'Programme et rapports d’audit interne', 'en' => 'Internal audit programme and reports', 'keywords' => ['audit']],
        ['key' => 'REVIEW', 'clause' => '9.3', 'fr' => 'Comptes rendus de revue de direction', 'en' => 'Management review minutes', 'keywords' => ['revue de direction', 'management review', 'compte rendu']],
        ['key' => 'NONCONFORMITY', 'clause' => '10.2', 'fr' => 'Non-conformités et actions correctives', 'en' => 'Nonconformities and corrective actions', 'keywords' => ['non-conformit', 'nonconformit', 'corrective', 'capa']],
    ];

    public function __construct(
        private CurrentUser $currentUser,
        private AiSystemRepository $systems,
        private AiImpactAssessmentRepository $assessments,
        private UserRepository $users,
        private IsmsDocumentRepository $documents,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/systems', methods: ['GET'])]
    public function systems(): JsonResponse
    {
        return new JsonResponse(array_map($this->system(...), $this->systems->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/systems', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function createSystem(Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $data = $request->toArray();
        $owner = $this->owner($data, $actor, $actor);
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        }
        try {
            $system = new AiSystem($actor->getOrganization(), $owner, (string) ($data['name'] ?? ''), (string) ($data['purpose'] ?? ''), (string) ($data['provider'] ?? ''), $this->nullableString($data['model'] ?? null), (array) ($data['dataCategories'] ?? []), (string) ($data['riskLevel'] ?? ''), (string) ($data['status'] ?? 'PLANNED'), $this->nullableString($data['humanOversight'] ?? null), $this->date($data['reviewAt'] ?? null));
        } catch (\InvalidArgumentException|\DateMalformedStringException $exception) {
            return $this->invalid($exception->getMessage());
        }
        $this->entityManager->persist($system);
        $this->entityManager->flush();

        return new JsonResponse($this->system($system), 201);
    }

    #[Route('/systems/{id<\d+>}', methods: ['PUT'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function updateSystem(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $system = $this->systems->findOneVisibleTo($id, $actor);
        if (!$system instanceof AiSystem) {
            return $this->notFound();
        }
        $data = $request->toArray();
        $owner = $this->owner($data, $actor, $system->getOwner());
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        }
        if ($system->isBuiltIn() && (array_key_exists('provider', $data) || array_key_exists('model', $data))) {
            // Le copilote est décrit par sa configuration IA : provider et modèle y restent la source de vérité.
            return $this->invalid('Le fournisseur et le modèle du copilote proviennent de la configuration IA.');
        }
        try {
            $system->update($owner, (string) ($data['name'] ?? $system->getName()), (string) ($data['purpose'] ?? $system->getPurpose()), (string) ($data['provider'] ?? $system->getProvider()), array_key_exists('model', $data) ? $this->nullableString($data['model']) : $system->getModel(), (array) ($data['dataCategories'] ?? $system->getDataCategories()), (string) ($data['riskLevel'] ?? $system->getRiskLevel()), (string) ($data['status'] ?? $system->getStatus()), array_key_exists('humanOversight', $data) ? $this->nullableString($data['humanOversight']) : $system->getHumanOversight(), array_key_exists('reviewAt', $data) ? $this->date($data['reviewAt']) : $system->getReviewAt());
        } catch (\InvalidArgumentException|\DateMalformedStringException $exception) {
            return $this->invalid($exception->getMessage());
        }
        $this->entityManager->flush();

        return new JsonResponse($this->system($system));
    }

    #[Route('/systems/{id<\d+>}/impact-assessments', methods: ['GET'])]
    public function listAssessments(int $id): JsonResponse
    {
        $system = $this->systems->findOneVisibleTo($id, $this->currentUser->get());
        if (!$system instanceof AiSystem) {
            return $this->notFound();
        }

        return new JsonResponse(array_map($this->assessment(...), $this->assessments->findForSystem($system)));
    }

    #[Route('/systems/{id<\d+>}/impact-assessments', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function createAssessment(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $system = $this->systems->findOneVisibleTo($id, $actor);
        if (!$system instanceof AiSystem) {
            return $this->notFound();
        }
        $assessment = new AiImpactAssessment($system, $actor);

        return $this->save($assessment, $request->toArray(), true);
    }

    #[Route('/impact-assessments/{id<\d+>}', methods: ['PUT'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function updateAssessment(int $id, Request $request): JsonResponse
    {
        $assessment = $this->assessments->findOneVisibleTo($id, $this->currentUser->get());

        return $assessment instanceof AiImpactAssessment ? $this->save($assessment, $request->toArray(), false) : $this->notFound();
    }

    #[Route('/impact-assessments/{id<\d+>}/submit', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function submit(int $id): JsonResponse
    {
        return $this->transition($id, static fn (AiImpactAssessment $item, User $actor) => $item->submit());
    }

    #[Route('/impact-assessments/{id<\d+>}/approve', methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function approve(int $id): JsonResponse
    {
        return $this->transition($id, static fn (AiImpactAssessment $item, User $actor) => $item->approve($actor));
    }

    #[Route('/overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        $items = [];
        $needsAssessment = 0;
        foreach ($this->systems->findVisibleTo($this->currentUser->get()) as $system) {
            $missing = in_array($system->getRiskLevel(), ['HIGH', 'LIMITED'], true) && 'RETIRED' !== $system->getStatus() && !$this->assessments->hasApprovedFor($system);
            $needsAssessment += $missing ? 1 : 0;
            $items[] = ['id' => $system->getId(), 'name' => $system->getName(), 'riskLevel' => $system->getRiskLevel(), 'status' => $system->getStatus(), 'missingApprovedAssessment' => $missing];
        }

        return new JsonResponse(['systems' => count($items), 'missingApprovedAssessment' => $needsAssessment, 'items' => $items]);
    }

    #[Route('/mandatory-documents', methods: ['GET'])]
    public function mandatoryDocuments(): JsonResponse
    {
        $documents = array_filter($this->documents->findVisibleTo($this->currentUser->get()), static fn ($document): bool => 'ARCHIVED' !== $document->getStatus());
        $result = [];
        foreach (self::MANDATORY_DOCUMENTS as $required) {
            $matches = [];
            foreach ($documents as $document) {
                $haystack = mb_strtolower($document->getTitle().' '.$document->getCategory());
                foreach ($required['keywords'] as $keyword) {
                    if (str_contains($haystack, mb_strtolower($keyword))) {
                        $matches[] = ['id' => $document->getId(), 'title' => $document->getTitle(), 'status' => $document->getStatus()];
                        break;
                    }
                }
            }
            $result[] = ['key' => $required['key'], 'clause' => $required['clause'], 'title' => ['fr' => $required['fr'], 'en' => $required['en']], 'covered' => [] !== $matches, 'documents' => array_slice($matches, 0, 5)];
        }

        return new JsonResponse(['items' => $result, 'missing' => count(array_filter($result, static fn (array $item): bool => !$item['covered']))]);
    }

    /** @param array<string, mixed> $data */
    private function save(AiImpactAssessment $assessment, array $data, bool $create): JsonResponse
    {
        try {
            $assessment->update(
                (string) ($data['affectedGroups'] ?? $assessment->getAffectedGroups()),
                (string) ($data['benefits'] ?? $assessment->getBenefits()),
                (string) ($data['harms'] ?? $assessment->getHarms()),
                (string) ($data['mitigations'] ?? $assessment->getMitigations()),
                (string) ($data['residualRisk'] ?? $assessment->getResidualRisk()),
                array_key_exists('reviewAt', $data) ? $this->date($data['reviewAt']) : $assessment->getReviewAt(),
            );
        } catch (\InvalidArgumentException|\DateMalformedStringException $exception) {
            return $this->invalid($exception->getMessage());
        }
        if ($create) {
            $this->entityManager->persist($assessment);
        }
        $this->entityManager->flush();

        return new JsonResponse($this->assessment($assessment), $create ? 201 : 200);
    }

    /** @param callable(AiImpactAssessment, User): mixed $action */
    private function transition(int $id, callable $action): JsonResponse
    {
        $actor = $this->currentUser->get();
        $assessment = $this->assessments->findOneVisibleTo($id, $actor);
        if (!$assessment instanceof AiImpactAssessment) {
            return $this->notFound();
        }
        try {
            $action($assessment, $actor);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }
        $this->entityManager->flush();

        return new JsonResponse($this->assessment($assessment));
    }

    /** @param array<string, mixed> $data */
    private function owner(array $data, User $actor, User $default): ?User
    {
        return array_key_exists('ownerId', $data) ? $this->users->findOneVisibleTo((int) $data['ownerId'], $actor) : $default;
    }

    private function nullableString(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        return empty($value) ? null : new \DateTimeImmutable((string) $value);
    }

    /** @return array<string, mixed> */
    private function system(AiSystem $system): array
    {
        $owner = $system->getOwner();

        return [
            'id' => $system->getId(), 'name' => $system->getName(), 'purpose' => $system->getPurpose(), 'provider' => $system->getProvider(), 'model' => $system->getModel(),
            'dataCategories' => $system->getDataCategories(), 'riskLevel' => $system->getRiskLevel(), 'status' => $system->getStatus(), 'humanOversight' => $system->getHumanOversight(),
            'reviewAt' => $system->getReviewAt()?->format('Y-m-d'), 'builtIn' => $system->isBuiltIn(),
            'owner' => ['id' => $owner->getId(), 'name' => trim($owner->getFirstName().' '.$owner->getLastName())],
            'approvedAssessment' => $this->assessments->hasApprovedFor($system), 'updatedAt' => $system->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function assessment(AiImpactAssessment $item): array
    {
        return [
            'id' => $item->getId(), 'systemId' => $item->getSystem()->getId(), 'status' => $item->getStatus(), 'affectedGroups' => $item->getAffectedGroups(), 'benefits' => $item->getBenefits(),
            'harms' => $item->getHarms(), 'mitigations' => $item->getMitigations(), 'residualRisk' => $item->getResidualRisk(), 'reviewAt' => $item->getReviewAt()?->format('Y-m-d'),
            'author' => ['id' => $item->getAuthor()->getId(), 'name' => trim($item->getAuthor()->getFirstName().' '.$item->getAuthor()->getLastName())],
            'reviewer' => null === $item->getReviewer() ? null : ['id' => $item->getReviewer()->getId(), 'name' => trim($item->getReviewer()->getFirstName().' '.$item->getReviewer()->getLastName())],
            'approvedAt' => $item->getApprovedAt()?->format(DATE_ATOM), 'updatedAt' => $item->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Élément introuvable.'], 404);
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_INPUT', 'message' => $message], 422);
    }
}
