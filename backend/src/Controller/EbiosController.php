<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Domain\Risk\EbiosWorkshopValidator;
use App\Entity\EbiosWorkshop;
use App\Entity\RiskAnalysis;
use App\Repository\EbiosWorkshopRepository;
use App\Repository\RiskAnalysisRepository;
use App\Security\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/ebios')]
final readonly class EbiosController
{
    public function __construct(private CurrentUser $currentUser, private RiskAnalysisRepository $analyses, private EbiosWorkshopRepository $workshops, private EbiosWorkshopValidator $validator, private PermissionChecker $permissions, private EntityManagerInterface $em)
    {
    }

    #[Route('/analyses', methods: ['GET'])]
    public function analyses(): JsonResponse
    {
        if (!$this->granted('ebios.read')) {
            return $this->forbidden();
        }
        $items = array_filter($this->analyses->search($this->currentUser->get()->getOrganization(), 1, 100), static fn (RiskAnalysis $analysis): bool => 'EBIOS_RM' === $analysis->getMethod());

        return new JsonResponse(array_map(fn (RiskAnalysis $analysis): array => ['id' => $analysis->getId(), 'title' => $analysis->getTitle(), 'version' => $analysis->getVersion(), 'status' => $analysis->getStatus(), 'workshops' => array_map($this->response(...), $this->workshops->forAnalysis($analysis))], array_values($items)));
    }

    #[Route('/analyses/{analysisId<\d+>}/workshops/{number<[1-5]>}', methods: ['GET'])]
    public function show(int $analysisId, int $number): JsonResponse
    {
        if (!$this->granted('ebios.read')) {
            return $this->forbidden();
        }
        $analysis = $this->analysis($analysisId);
        if (!$analysis instanceof RiskAnalysis) {
            return $this->notFound();
        }
        $workshop = $this->workshops->findOneBy(['analysis' => $analysis, 'number' => $number]);

        return new JsonResponse($workshop instanceof EbiosWorkshop ? $this->response($workshop) : ['analysisId' => $analysisId, 'number' => $number, 'status' => 'DRAFT', 'version' => 0, 'payload' => [], 'missingFields' => []]);
    }

    #[Route('/analyses/{analysisId<\d+>}/workshops/{number<[1-5]>}', methods: ['PUT'])]
    public function update(int $analysisId, int $number, Request $request): JsonResponse
    {
        if (!$this->granted('ebios.update')) {
            return $this->forbidden();
        }
        $analysis = $this->analysis($analysisId);
        if (!$analysis instanceof RiskAnalysis) {
            return $this->notFound();
        }
        $payload = $request->toArray()['payload'] ?? null;
        if (!is_array($payload)) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => 'Le contenu structuré de l’atelier est obligatoire.'], 422);
        }
        $workshop = $this->workshops->findOneBy(['analysis' => $analysis, 'number' => $number]);
        try {
            $workshop ??= new EbiosWorkshop($analysis, $this->currentUser->get(), $number);
            $workshop->update($payload, $this->currentUser->get());
            $this->em->persist($workshop);
            $this->em->flush();

            return new JsonResponse($this->response($workshop), 200);
        } catch (\Throwable $error) {
            return new JsonResponse(['code' => 'INVALID_WORKSHOP', 'message' => $error->getMessage()], 422);
        }
    }

    #[Route('/analyses/{analysisId<\d+>}/workshops/{number<[1-5]>}/validate', methods: ['POST'])]
    public function validate(int $analysisId, int $number): JsonResponse
    {
        if (!$this->granted('ebios.validate')) {
            return $this->forbidden();
        }
        $analysis = $this->analysis($analysisId);
        $workshop = $analysis instanceof RiskAnalysis ? $this->workshops->findOneBy(['analysis' => $analysis, 'number' => $number]) : null;
        if (!$workshop instanceof EbiosWorkshop) {
            return $this->notFound();
        }
        if ($number > 1) {
            $previous = $this->workshops->findOneBy(['analysis' => $analysis, 'number' => $number - 1]);
            if (!$previous instanceof EbiosWorkshop || 'VALIDATED' !== $previous->getStatus()) {
                return new JsonResponse(['code' => 'PREVIOUS_WORKSHOP_REQUIRED', 'message' => 'L’atelier précédent doit être validé.'], 422);
            }
        }
        $missing = $this->validator->violations($number, $workshop->getPayload());
        if ([] !== $missing) {
            return new JsonResponse(['code' => 'INCOMPLETE_WORKSHOP', 'message' => 'L’atelier est incomplet.', 'missingFields' => $missing], 422);
        }
        try {
            $workshop->validate($this->currentUser->get());
            $this->em->flush();

            return new JsonResponse($this->response($workshop));
        } catch (\Throwable $error) {
            return new JsonResponse(['code' => 'INVALID_VALIDATION', 'message' => $error->getMessage()], 422);
        }
    }

    private function analysis(int $id): ?RiskAnalysis
    {
        $analysis = $this->analyses->visible($id, $this->currentUser->get()->getOrganization());

        return $analysis instanceof RiskAnalysis && 'EBIOS_RM' === $analysis->getMethod() ? $analysis : null;
    }

    private function granted(string $permission): bool
    {
        return $this->permissions->isGranted($this->currentUser->get(), $permission);
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['code' => 'FORBIDDEN', 'message' => 'Permission insuffisante.'], 403);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Analyse ou atelier EBIOS introuvable.'], 404);
    }

    /** @return array<string, mixed> */
    private function response(EbiosWorkshop $workshop): array
    {
        return ['id' => $workshop->getId(), 'analysisId' => $workshop->getAnalysis()->getId(), 'number' => $workshop->getNumber(), 'status' => $workshop->getStatus(), 'version' => $workshop->getVersion(), 'payload' => $workshop->getPayload(), 'missingFields' => $this->validator->violations($workshop->getNumber(), $workshop->getPayload()), 'ownerId' => $workshop->getOwner()->getId(), 'validatedById' => $workshop->getValidatedBy()?->getId(), 'updatedAt' => $workshop->getUpdatedAt()->format(DATE_ATOM), 'validatedAt' => $workshop->getValidatedAt()?->format(DATE_ATOM)];
    }
}
