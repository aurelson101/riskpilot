<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Api\Dto\RiskScenarioInput;
use App\Api\JsonInputMapper;
use App\Application\CurrentUser;
use App\Application\NotificationService;
use App\Domain\Risk\RiskCalculation;
use App\Entity\RiskScenario;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\RiskScenarioRepository;
use App\Repository\ScopeRepository;
use App\Repository\SecurityControlRepository;
use App\Repository\ThreatRepository;
use App\Repository\UserRepository;
use App\Repository\VulnerabilityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/risks')]
final readonly class RiskScenarioController
{
    public function __construct(
        private RiskScenarioRepository $risks, private ScopeRepository $scopes, private AssetRepository $assets,
        private ThreatRepository $threats, private VulnerabilityRepository $vulnerabilities,
        private SecurityControlRepository $controls, private UserRepository $users, private CurrentUser $currentUser,
        private RiskCalculation $calculation, private EntityManagerInterface $entityManager,
        private JsonInputMapper $mapper, private ApiResponseFactory $responses,
        private NotificationService $notifications,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->responses->riskScenario(...), $this->risks->findVisibleTo($this->currentUser->get())));
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $risk = $this->risks->findOneVisibleTo($id, $this->currentUser->get());

        return null === $risk ? $this->notFound() : new JsonResponse($this->responses->riskScenario($risk));
    }

    #[Route('', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        return $this->save(null, $request);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function update(int $id, Request $request): JsonResponse
    {
        $risk = $this->risks->findOneVisibleTo($id, $this->currentUser->get());

        return null === $risk ? $this->notFound() : $this->save($risk, $request);
    }

    #[Route('/{id<\d+>}', methods: ['DELETE'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function archive(int $id): JsonResponse
    {
        $risk = $this->risks->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $risk) {
            return $this->notFound();
        }
        $risk->setStatus('ARCHIVED');
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    private function save(?RiskScenario $risk, Request $request): JsonResponse
    {
        [$input, $violations] = $this->mapper->map($request, RiskScenarioInput::class);
        if (count($violations) > 0) {
            return $this->responses->validationError($violations);
        }
        $actor = $this->currentUser->get();
        $scope = null === $input->scopeId ? null : $this->scopes->findOneVisibleTo($input->scopeId, $actor);
        $asset = null === $input->assetId ? null : $this->assets->findOneVisibleTo($input->assetId, $actor);
        $threat = null === $input->threatId ? null : $this->threats->findOneVisibleTo($input->threatId, $actor);
        $owner = null === $input->riskOwnerId ? null : $this->users->findOneVisibleTo($input->riskOwnerId, $actor);
        $vulnerabilityIds = array_values(array_unique($input->vulnerabilityIds));
        $controlIds = array_values(array_unique($input->currentControlIds));
        $vulnerabilities = $this->vulnerabilities->findAllVisibleByIds($vulnerabilityIds, $actor);
        $controls = $this->controls->findAllVisibleByIds($controlIds, $actor);
        if (null === $scope || null === $asset || null === $threat || null === $owner || count($vulnerabilities) !== count($vulnerabilityIds) || count($controls) !== count($controlIds)) {
            return new JsonResponse(['code' => 'INVALID_RELATION', 'message' => 'Une ou plusieurs relations sont invalides.'], 422);
        }
        $created = null === $risk;
        $risk ??= new RiskScenario($input->title, $actor->getOrganization(), $scope, $asset, $threat, $owner);
        $risk->setTitle($input->title)->setDescription($input->description)->setScope($scope)->setAsset($asset)->setThreat($threat)->setRiskOwner($owner)->replaceVulnerabilities($vulnerabilities)->replaceCurrentControls($controls)
            ->setEvaluations($input->likelihood, $input->impact, $this->calculation->score($input->likelihood, $input->impact), $input->currentLikelihood, $input->currentImpact, $this->calculation->score($input->currentLikelihood, $input->currentImpact), $input->residualLikelihood, $input->residualImpact, $this->calculation->score($input->residualLikelihood, $input->residualImpact))
            ->setTreatmentDecision($input->treatmentDecision)->setStatus($input->status)->setReviewDate(null === $input->reviewDate ? null : new \DateTimeImmutable($input->reviewDate));
        $this->entityManager->persist($risk);
        if ($created && $risk->getGrossRiskScore() > $actor->getOrganization()->getRiskThresholds()['highMax']) {
            $this->notifications->notify($owner, 'CRITICAL_RISK_CREATED', 'Risque critique créé', sprintf('Le scénario « %s » a un score brut de %d.', $risk->getTitle(), $risk->getGrossRiskScore()), '/risks');
        }
        if ($created && 'IN_REVIEW' === $risk->getStatus()) {
            $this->notifications->notify($owner, 'RISK_REVIEW_REQUIRED', 'Risque à valider', sprintf('Le scénario « %s » est en attente de validation.', $risk->getTitle()), '/risks');
        }
        $this->entityManager->flush();

        return new JsonResponse($this->responses->riskScenario($risk), $created ? 201 : 200);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Risque introuvable.'], 404);
    }
}
