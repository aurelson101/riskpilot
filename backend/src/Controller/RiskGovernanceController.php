<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\RiskGovernancePolicy;
use App\Entity\User;
use App\Repository\RiskGovernancePolicyRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/risk-governance/policies')]
final readonly class RiskGovernanceController
{
    public function __construct(private RiskGovernancePolicyRepository $policies, private UserRepository $users, private CurrentUser $currentUser, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->serialize(...), $this->policies->findVisibleTo($this->currentUser->get())));
    }

    #[Route('', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        return $this->save(null, $request);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function update(int $id, Request $request): JsonResponse
    {
        $policy = $this->policies->findOneVisibleTo($id, $this->currentUser->get());

        return null === $policy ? $this->notFound() : $this->save($policy, $request);
    }

    #[Route('/{id<\d+>}', methods: ['DELETE'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function delete(int $id): JsonResponse
    {
        $policy = $this->policies->findOneVisibleTo($id, $this->currentUser->get());
        if (null === $policy) {
            return $this->notFound();
        }
        $this->entityManager->remove($policy);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    private function save(?RiskGovernancePolicy $policy, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $input = $request->toArray();
        $domain = trim((string) ($input['domain'] ?? ''));
        $family = trim((string) ($input['family'] ?? ''));
        $appetite = filter_var($input['appetiteScore'] ?? null, FILTER_VALIDATE_INT);
        $tolerance = filter_var($input['toleranceScore'] ?? null, FILTER_VALIDATE_INT);
        $capacity = filter_var($input['capacityScore'] ?? null, FILTER_VALIDATE_INT);
        $method = (string) ($input['method'] ?? 'SIMPLIFIED');
        $ownerId = filter_var($input['ownerId'] ?? null, FILTER_VALIDATE_INT);
        $owner = false === $ownerId ? null : $this->users->findOneVisibleTo($ownerId, $actor);
        if ('' === $domain || '' === $family || mb_strlen($domain) > 100 || mb_strlen($family) > 100 || false === $appetite || false === $tolerance || false === $capacity || $appetite < 1 || $capacity > 25 || !in_array($method, RiskGovernancePolicy::METHODS, true) || null === $owner) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => 'Politique de risque invalide. Scores attendus entre 1 et 25.'], 422);
        }
        $created = null === $policy;
        $policy ??= new RiskGovernancePolicy($actor->getOrganization(), $domain, $family, $owner);
        try {
            $policy->update($domain, $family, $appetite, $tolerance, $capacity, $method, isset($input['rationale']) ? (string) $input['rationale'] : null, $owner);
            $this->entityManager->persist($policy);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $error) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => $error->getMessage()], 422);
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse(['code' => 'DUPLICATE_POLICY', 'message' => 'Une politique existe déjà pour ce domaine et cette famille.'], 409);
        }

        return new JsonResponse($this->serialize($policy), $created ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function serialize(RiskGovernancePolicy $policy): array
    {
        $owner = $policy->getOwner();

        return ['id' => $policy->getId(), 'domain' => $policy->getDomain(), 'family' => $policy->getFamily(), 'appetiteScore' => $policy->getAppetiteScore(), 'toleranceScore' => $policy->getToleranceScore(), 'capacityScore' => $policy->getCapacityScore(), 'method' => $policy->getMethod(), 'rationale' => $policy->getRationale(), 'owner' => ['id' => $owner->getId(), 'firstName' => $owner->getFirstName(), 'lastName' => $owner->getLastName(), 'email' => $owner->getEmail()], 'updatedAt' => $policy->getUpdatedAt()->format(DATE_ATOM)];
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Politique introuvable.'], 404);
    }
}
