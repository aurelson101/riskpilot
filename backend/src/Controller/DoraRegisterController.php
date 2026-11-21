<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Domain\Regulatory\DoraRegisterCompleteness;
use App\Entity\IctArrangement;
use App\Entity\ThirdParty;
use App\Entity\User;
use App\Repository\IctArrangementRepository;
use App\Repository\ThirdPartyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Registre d'informations DORA (art. 28 §3) : accords de services TIC par prestataire, complétude et export. */
#[Route('/api/dora')]
final readonly class DoraRegisterController
{
    public function __construct(
        private CurrentUser $currentUser,
        private IctArrangementRepository $arrangements,
        private ThirdPartyRepository $thirdParties,
        private UserRepository $users,
        private DoraRegisterCompleteness $completeness,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/arrangements', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $now = new \DateTimeImmutable();
        $items = array_map(fn (IctArrangement $arrangement): array => $this->item($arrangement, $now), $this->arrangements->findVisibleTo($this->currentUser->get()));
        $active = array_filter($items, static fn (array $item): bool => 'TERMINATED' !== $item['status']);

        return new JsonResponse(['summary' => [
            'arrangements' => count($items),
            'criticalFunctions' => count(array_filter($active, static fn (array $item): bool => $item['supportsCriticalFunction'])),
            'withGaps' => count(array_filter($active, static fn (array $item): bool => [] !== $item['gaps'])),
        ], 'items' => $items]);
    }

    #[Route('/arrangements', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $data = $request->toArray();
        $thirdParty = $this->thirdParties->findOneVisibleTo((int) ($data['thirdPartyId'] ?? 0), $actor);
        $owner = array_key_exists('ownerId', $data) ? $this->users->findOneVisibleTo((int) $data['ownerId'], $actor) : $actor;
        if (!$thirdParty instanceof ThirdParty || null === $owner) {
            return $this->invalid('Prestataire ou responsable invalide.');
        }
        try {
            $arrangement = new IctArrangement($actor->getOrganization(), $thirdParty, $owner, (string) ($data['serviceType'] ?? ''), (string) ($data['description'] ?? ''));
            $this->apply($arrangement, $data, $owner);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }
        $this->entityManager->persist($arrangement);
        $this->entityManager->flush();

        return new JsonResponse($this->item($arrangement, new \DateTimeImmutable()), 201);
    }

    #[Route('/arrangements/{id<\d+>}', methods: ['PUT'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function update(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $arrangement = $this->arrangements->findOneVisibleTo($id, $actor);
        if (!$arrangement instanceof IctArrangement) {
            return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Accord introuvable.'], 404);
        }
        $data = $request->toArray();
        $owner = array_key_exists('ownerId', $data) ? $this->users->findOneVisibleTo((int) $data['ownerId'], $actor) : $arrangement->getOwner();
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        }
        try {
            $this->apply($arrangement, $data, $owner);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        }
        $this->entityManager->flush();

        return new JsonResponse($this->item($arrangement, new \DateTimeImmutable()));
    }

    #[Route('/register.csv', methods: ['GET'])]
    public function export(): Response
    {
        $header = ['Prestataire', 'LEI', 'Type de service', 'Description', 'Fonction critique ou importante', 'Fonction soutenue', 'Référence du contrat', 'Début', 'Fin', 'Localisation des données', 'Sous-traitants', 'Substituabilité', 'Dernier test du plan de sortie', 'Statut', 'Écarts'];
        $rows = [$header];
        $now = new \DateTimeImmutable();
        foreach ($this->arrangements->findVisibleTo($this->currentUser->get()) as $item) {
            $rows[] = [$item->getThirdParty()->getName(), $item->getProviderLei(), $item->getServiceType(), $item->getDescription(), $item->supportsCriticalFunction() ? 'oui' : 'non', $item->getFunctionSupported(), $item->getContractReference(), $item->getStartsAt()?->format('Y-m-d'), $item->getEndsAt()?->format('Y-m-d'), $item->getDataLocation(), $item->getSubcontractors(), $item->getSubstitutability(), $item->getExitPlanTestedAt()?->format('Y-m-d'), $item->getStatus(), implode(' ', $this->completeness->gaps($item, $now))];
        }
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new \RuntimeException('Unable to create export.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, array_map($this->safeCell(...), $row), ';', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return new Response(false === $content ? '' : $content, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="registre-dora-'.$now->format('Y-m-d').'.csv"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function apply(IctArrangement $arrangement, array $data, User $owner): void
    {
        foreach (['supportsCriticalFunction'] as $flag) {
            if (array_key_exists($flag, $data) && !is_bool($data[$flag])) {
                throw new \InvalidArgumentException(sprintf('Le champ %s doit être un booléen.', $flag));
            }
        }
        $text = static fn (string $key, ?string $current): ?string => array_key_exists($key, $data) ? (null === $data[$key] ? null : (string) $data[$key]) : $current;
        $arrangement->update(
            $owner,
            (string) ($data['serviceType'] ?? $arrangement->getServiceType()),
            (string) ($data['description'] ?? $arrangement->getDescription()),
            $data['supportsCriticalFunction'] ?? $arrangement->supportsCriticalFunction(),
            $text('functionSupported', $arrangement->getFunctionSupported()),
            $text('providerLei', $arrangement->getProviderLei()),
            $text('contractReference', $arrangement->getContractReference()),
            array_key_exists('startsAt', $data) ? $this->date($data['startsAt']) : $arrangement->getStartsAt(),
            array_key_exists('endsAt', $data) ? $this->date($data['endsAt']) : $arrangement->getEndsAt(),
            $text('dataLocation', $arrangement->getDataLocation()),
            $text('subcontractors', $arrangement->getSubcontractors()),
            (string) ($data['substitutability'] ?? $arrangement->getSubstitutability()),
            array_key_exists('exitPlanTestedAt', $data) ? $this->date($data['exitPlanTestedAt']) : $arrangement->getExitPlanTestedAt(),
            (string) ($data['status'] ?? $arrangement->getStatus()),
        );
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC')) : false;
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Les dates doivent être au format AAAA-MM-JJ.');
        }

        return $date;
    }

    /** @return array<string, mixed> */
    private function item(IctArrangement $arrangement, \DateTimeImmutable $now): array
    {
        $owner = $arrangement->getOwner();

        return [
            'id' => $arrangement->getId(), 'thirdParty' => ['id' => $arrangement->getThirdParty()->getId(), 'name' => $arrangement->getThirdParty()->getName()],
            'serviceType' => $arrangement->getServiceType(), 'description' => $arrangement->getDescription(), 'supportsCriticalFunction' => $arrangement->supportsCriticalFunction(), 'functionSupported' => $arrangement->getFunctionSupported(),
            'providerLei' => $arrangement->getProviderLei(), 'contractReference' => $arrangement->getContractReference(), 'startsAt' => $arrangement->getStartsAt()?->format('Y-m-d'), 'endsAt' => $arrangement->getEndsAt()?->format('Y-m-d'),
            'dataLocation' => $arrangement->getDataLocation(), 'subcontractors' => $arrangement->getSubcontractors(), 'substitutability' => $arrangement->getSubstitutability(), 'exitPlanTestedAt' => $arrangement->getExitPlanTestedAt()?->format('Y-m-d'),
            'status' => $arrangement->getStatus(), 'owner' => ['id' => $owner->getId(), 'name' => trim($owner->getFirstName().' '.$owner->getLastName())],
            'gaps' => $this->completeness->gaps($arrangement, $now), 'updatedAt' => $arrangement->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    private function safeCell(int|float|string|null $value): string
    {
        $text = (string) $value;

        return 1 === preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_INPUT', 'message' => $message], 422);
    }
}
