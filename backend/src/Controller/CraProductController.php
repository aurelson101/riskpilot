<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Domain\Regulatory\CraProductReadiness;
use App\Entity\DigitalProduct;
use App\Entity\User;
use App\Repository\DigitalProductRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Inventaire des produits comportant des éléments numériques et écarts de préparation au Cyber Resilience Act. */
#[Route('/api/cra/products')]
final readonly class CraProductController
{
    public function __construct(
        private CurrentUser $currentUser,
        private DigitalProductRepository $products,
        private UserRepository $users,
        private CraProductReadiness $readiness,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $now = new \DateTimeImmutable();
        $items = array_map(fn (DigitalProduct $product): array => $this->item($product, $now), $this->products->findVisibleTo($this->currentUser->get()));
        $withGaps = count(array_filter($items, static fn (array $item): bool => [] !== $item['gaps']));

        return new JsonResponse(['summary' => ['products' => count($items), 'withGaps' => $withGaps, 'onMarket' => count(array_filter($items, static fn (array $item): bool => 'ON_MARKET' === $item['status']))], 'items' => $items]);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $data = $request->toArray();
        $owner = array_key_exists('ownerId', $data) ? $this->users->findOneVisibleTo((int) $data['ownerId'], $actor) : $actor;
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        }
        try {
            $product = new DigitalProduct($actor->getOrganization(), $owner, (string) ($data['name'] ?? ''), (string) ($data['version'] ?? ''));
            $this->apply($product, $data, $owner);
            $this->entityManager->persist($product);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->invalid('Ce produit et cette version existent déjà.');
        }

        return new JsonResponse($this->item($product, new \DateTimeImmutable()), 201);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])]
    #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function update(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser->get();
        $product = $this->products->findOneVisibleTo($id, $actor);
        if (!$product instanceof DigitalProduct) {
            return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Produit introuvable.'], 404);
        }
        $data = $request->toArray();
        $owner = array_key_exists('ownerId', $data) ? $this->users->findOneVisibleTo((int) $data['ownerId'], $actor) : $product->getOwner();
        if (null === $owner) {
            return $this->invalid('Responsable invalide.');
        }
        try {
            $this->apply($product, $data, $owner);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->invalid('Ce produit et cette version existent déjà.');
        }

        return new JsonResponse($this->item($product, new \DateTimeImmutable()));
    }

    /** @param array<string, mixed> $data */
    private function apply(DigitalProduct $product, array $data, User $owner): void
    {
        $text = static fn (string $key, ?string $current): ?string => array_key_exists($key, $data) ? (null === $data[$key] ? null : (string) $data[$key]) : $current;
        $product->update(
            $owner,
            (string) ($data['name'] ?? $product->getName()),
            (string) ($data['version'] ?? $product->getVersion()),
            (string) ($data['category'] ?? $product->getCategory()),
            (string) ($data['role'] ?? $product->getRole()),
            (string) ($data['status'] ?? $product->getStatus()),
            array_key_exists('placedOnMarketAt', $data) ? $this->date($data['placedOnMarketAt']) : $product->getPlacedOnMarketAt(),
            array_key_exists('supportEndsAt', $data) ? $this->date($data['supportEndsAt']) : $product->getSupportEndsAt(),
            $text('sbomReference', $product->getSbomReference()),
            $text('vulnerabilityContact', $product->getVulnerabilityContact()),
            (string) ($data['conformityRoute'] ?? $product->getConformityRoute()),
            $text('declarationReference', $product->getDeclarationReference()),
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
    private function item(DigitalProduct $product, \DateTimeImmutable $now): array
    {
        $owner = $product->getOwner();

        return [
            'id' => $product->getId(), 'name' => $product->getName(), 'version' => $product->getVersion(), 'category' => $product->getCategory(), 'role' => $product->getRole(), 'status' => $product->getStatus(),
            'placedOnMarketAt' => $product->getPlacedOnMarketAt()?->format('Y-m-d'), 'supportEndsAt' => $product->getSupportEndsAt()?->format('Y-m-d'),
            'sbomReference' => $product->getSbomReference(), 'vulnerabilityContact' => $product->getVulnerabilityContact(), 'conformityRoute' => $product->getConformityRoute(), 'declarationReference' => $product->getDeclarationReference(),
            'owner' => ['id' => $owner->getId(), 'name' => trim($owner->getFirstName().' '.$owner->getLastName())],
            'gaps' => $this->readiness->gaps($product, $now), 'updatedAt' => $product->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'INVALID_INPUT', 'message' => $message], 422);
    }
}
