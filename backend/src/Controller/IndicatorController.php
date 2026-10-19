<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\Indicator;
use App\Entity\IndicatorValue;
use App\Entity\User;
use App\Repository\IndicatorRepository;
use App\Repository\IndicatorValueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/indicators')]
final readonly class IndicatorController
{
    public function __construct(private IndicatorRepository $indicators, private IndicatorValueRepository $values, private CurrentUser $currentUser, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map($this->serializeIndicator(...), $this->indicators->findForOrganization($this->currentUser->get()->getOrganization())));
    }

    #[Route('', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        foreach (['code' => 80, 'name' => 180, 'kind' => 10, 'unit' => 40, 'frequency' => 20] as $field => $maxLength) {
            if (!is_string($data[$field] ?? null) || '' === trim($data[$field]) || mb_strlen($data[$field]) > $maxLength) {
                return $this->invalid('Code, name, kind, unit and frequency are required and must respect their size limits.');
            }
        }
        foreach (['formula' => 10000, 'source' => 255] as $field => $maxLength) {
            if (array_key_exists($field, $data) && null !== $data[$field] && (!is_string($data[$field]) || mb_strlen($data[$field]) > $maxLength)) {
                return $this->invalid(sprintf('%s is invalid or too long.', $field));
            }
        }
        if (array_key_exists('active', $data) && !is_bool($data['active'])) {
            return $this->invalid('active must be a boolean.');
        }
        $targetValue = $data['target'] ?? null;
        if (null !== $targetValue && !$this->validDecimal($targetValue)) {
            return $this->invalid('target must be a finite decimal within the supported range.');
        }
        $thresholds = $data['thresholds'] ?? [];
        if (!is_array($thresholds) || count($thresholds) > 100) {
            return $this->invalid('thresholds must be an object containing at most 100 numeric values.');
        }
        $normalizedThresholds = [];
        foreach ($thresholds as $key => $value) {
            if (!is_string($key) || '' === trim($key) || mb_strlen($key) > 80 || (!is_int($value) && !is_float($value)) || (is_float($value) && !is_finite($value))) {
                return $this->invalid('Each threshold must have a valid name and a finite numeric value.');
            }
            $normalizedThresholds[$key] = $value;
        }
        $code = trim($data['code']);
        $name = trim($data['name']);
        $kind = $data['kind'];
        $unit = trim($data['unit']);
        $frequency = $data['frequency'];
        if (!in_array($kind, Indicator::KINDS, true) || !in_array($frequency, Indicator::FREQUENCIES, true)) {
            return $this->invalid('kind or frequency is invalid.');
        }
        $organization = $this->currentUser->get()->getOrganization();
        if (null !== $this->indicators->findOneBy(['organization' => $organization, 'code' => $code])) {
            return new JsonResponse(['code' => 'DUPLICATE_CODE', 'message' => 'This indicator code already exists.'], 409);
        }
        $target = null === $targetValue ? null : number_format((float) $targetValue, 6, '.', '');
        $formula = is_string($data['formula'] ?? null) ? $data['formula'] : null;
        $source = is_string($data['source'] ?? null) ? $data['source'] : null;
        $indicator = (new Indicator($organization, $code, $name, $kind, $unit, $frequency))->configure($formula, $source, $target, $normalizedThresholds, $data['active'] ?? true);
        $this->entityManager->persist($indicator);
        $this->entityManager->flush();

        return new JsonResponse($this->serializeIndicator($indicator), 201);
    }

    #[Route('/{id<\d+>}/values', methods: ['GET'])]
    public function history(int $id, Request $request): JsonResponse
    {
        $indicator = $this->findIndicator($id);
        if (null === $indicator) {
            return $this->notFound();
        }
        $criteria = ['indicator' => $indicator];
        $items = $this->values->findBy($criteria, ['measuredAt' => 'DESC'], max(1, min(500, $request->query->getInt('limit', 100))));

        return new JsonResponse(['indicator' => $this->serializeIndicator($indicator), 'values' => array_map($this->serializeValue(...), $items)]);
    }

    #[Route('/{id<\d+>}/values', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function record(int $id, Request $request): JsonResponse
    {
        $indicator = $this->findIndicator($id);
        if (null === $indicator) {
            return $this->notFound();
        }
        $data = $request->toArray();
        if (!$this->validDecimal($data['value'] ?? null)
            || !is_string($data['measuredAt'] ?? null) || '' === trim($data['measuredAt']) || mb_strlen($data['measuredAt']) > 64
            || !is_string($data['idempotencyKey'] ?? null) || '' === trim($data['idempotencyKey']) || mb_strlen($data['idempotencyKey']) > 120) {
            return $this->invalid('Value, measuredAt and idempotencyKey are invalid.');
        }
        foreach (['period' => 80, 'comment' => 10000, 'evidence' => 2048, 'source' => 255] as $field => $maxLength) {
            if (array_key_exists($field, $data) && null !== $data[$field] && (!is_string($data[$field]) || mb_strlen($data[$field]) > $maxLength)) {
                return $this->invalid(sprintf('%s is invalid or too long.', $field));
            }
        }
        $key = trim($data['idempotencyKey']);
        $existing = $this->values->findOneBy(['indicator' => $indicator, 'idempotencyKey' => $key]);
        if (null !== $existing) {
            return new JsonResponse($this->serializeValue($existing));
        }
        try {
            $measuredAt = new \DateTimeImmutable($data['measuredAt']);
        } catch (\Exception) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => 'measuredAt must be a valid ISO 8601 timestamp.'], 422);
        }
        $evidence = $data['evidence'] ?? null;
        if (null !== $evidence && (!is_string($evidence) || false === filter_var($evidence, FILTER_VALIDATE_URL))) {
            return $this->invalid('Evidence must be a valid URL.');
        }
        $period = is_string($data['period'] ?? null) ? $data['period'] : null;
        $comment = is_string($data['comment'] ?? null) ? $data['comment'] : null;
        $source = is_string($data['source'] ?? null) ? $data['source'] : null;
        $value = (new IndicatorValue($indicator, number_format((float) $data['value'], 6, '.', ''), $measuredAt, $key))->describe($period, $comment, $evidence, $source);
        $this->entityManager->persist($value);
        $this->entityManager->flush();

        return new JsonResponse($this->serializeValue($value), 201);
    }

    #[Route('/{id<\d+>}/values/export', methods: ['GET'])]
    public function export(int $id): Response
    {
        $indicator = $this->findIndicator($id);
        if (null === $indicator) {
            return $this->notFound();
        }
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['id', 'value', 'unit', 'measuredAt', 'period', 'source', 'comment', 'version']);
        foreach ($this->values->findBy(['indicator' => $indicator], ['measuredAt' => 'ASC']) as $value) {
            fputcsv($stream, [$value->getId(), $value->getValue(), $indicator->getUnit(), $value->getMeasuredAt()->format(DATE_ATOM), $value->getPeriod(), $value->getSource(), $value->getComment(), $value->getVersion()]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => sprintf('attachment; filename="indicator-%s.csv"', $indicator->getCode())]);
    }

    #[Route('/{id<\d+>}/values/batch', methods: ['POST'])] #[IsGranted(User::ROLE_RISK_MANAGER)]
    public function batch(int $id, Request $request): JsonResponse
    {
        if (null === $this->findIndicator($id)) {
            return $this->notFound();
        }
        $rows = $request->toArray()['values'] ?? null;
        if (!is_array($rows) || count($rows) > 1000) {
            return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => 'values must contain at most 1000 rows.'], 422);
        }
        $results = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $results[] = ['index' => $index, 'status' => 422, 'error' => 'Invalid row.'];
                continue;
            }
            $response = $this->record($id, new Request(content: json_encode($row, JSON_THROW_ON_ERROR)));
            $results[] = ['index' => $index, 'status' => $response->getStatusCode(), 'result' => json_decode((string) $response->getContent(), true)];
        }

        return new JsonResponse(['results' => $results], 207);
    }

    private function findIndicator(int $id): ?Indicator
    {
        return $this->indicators->findOneForOrganization($id, $this->currentUser->get()->getOrganization());
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['code' => 'NOT_FOUND', 'message' => 'Indicator not found.'], 404);
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => $message], 422);
    }

    private function validDecimal(mixed $value): bool
    {
        if (null === $value || (!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            return false;
        }
        $number = (float) $value;

        return is_finite($number) && abs($number) < 100000000000000;
    }

    /** @return array<string, mixed> */
    private function serializeIndicator(Indicator $item): array
    {
        return ['id' => $item->getId(), 'code' => $item->getCode(), 'name' => $item->getName(), 'kind' => $item->getKind(), 'unit' => $item->getUnit(), 'frequency' => $item->getFrequency(), 'formula' => $item->getFormula(), 'source' => $item->getSource(), 'target' => $item->getTarget(), 'thresholds' => $item->getThresholds(), 'active' => $item->isActive(), 'createdAt' => $item->getCreatedAt()->format(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function serializeValue(IndicatorValue $item): array
    {
        return ['id' => $item->getId(), 'value' => $item->getValue(), 'measuredAt' => $item->getMeasuredAt()->format(DATE_ATOM), 'period' => $item->getPeriod(), 'comment' => $item->getComment(), 'evidence' => $item->getEvidence(), 'source' => $item->getSource(), 'idempotencyKey' => $item->getIdempotencyKey(), 'version' => $item->getVersion(), 'createdAt' => $item->getCreatedAt()->format(DATE_ATOM)];
    }
}
