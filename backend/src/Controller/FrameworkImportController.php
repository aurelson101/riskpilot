<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiResponseFactory;
use App\Api\Dto\FrameworkInput;
use App\Api\JsonInputMapper;
use App\Domain\Compliance\FrameworkCsvParser;
use App\Entity\Framework;
use App\Entity\Requirement;
use App\Entity\User;
use App\Repository\FrameworkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
final readonly class FrameworkImportController
{
    public function __construct(private FrameworkCsvParser $parser, private JsonInputMapper $mapper, private ApiResponseFactory $responses, private FrameworkRepository $frameworks, private EntityManagerInterface $em) {}

    #[Route('/api/frameworks/import/preview', methods: ['POST'])]
    #[Route('/api/frameworks/import/confirm', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 2 * FrameworkCsvParser::MAX_BYTES) {
            return new JsonResponse(['code' => 'IMPORT_TOO_LARGE', 'message' => 'Import trop volumineux.'], 413);
        }
        [$input, $violations] = $this->mapper->map($request, FrameworkInput::class);
        if (count($violations) > 0) return $this->responses->validationError($violations);
        $body = $request->toArray();
        if (!is_string($body['csv'] ?? null)) {
            return new JsonResponse(['code' => 'INVALID_CSV', 'message' => 'Fichier CSV requis.'], 422);
        }
        try { $rows = $this->parser->parse($body['csv']); }
        catch (\InvalidArgumentException $error) {
            return new JsonResponse(['code' => 'INVALID_CSV', 'message' => $error->getMessage()], 422);
        }
        if (null !== $this->frameworks->findOneBy(['name' => $input->name, 'version' => $input->version])) {
            return new JsonResponse(['code' => 'FRAMEWORK_EXISTS', 'message' => 'Cette version existe déjà. Aucun référentiel existant ne sera écrasé.'], 409);
        }
        $checksum = hash('sha256', json_encode([$input->name, $input->version, $input->description, $input->publisher, $input->status, $rows], JSON_THROW_ON_ERROR));
        if (str_ends_with($request->getPathInfo(), '/preview')) {
            return new JsonResponse(['checksum' => $checksum, 'count' => count($rows), 'requirements' => $rows]);
        }
        if (!is_string($body['checksum'] ?? null) || !hash_equals($checksum, $body['checksum'])) {
            return new JsonResponse(['code' => 'PREVIEW_CHANGED', 'message' => 'Prévisualisez à nouveau le fichier et les paramètres avant confirmation.'], 422);
        }
        // Serialize imports of the same global catalogue version without a schema migration.
        $framework = $this->em->wrapInTransaction(function () use ($input, $rows): ?Framework {
            $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => json_encode([$input->name, $input->version], JSON_THROW_ON_ERROR)]);
            if (null !== $this->frameworks->findOneBy(['name' => $input->name, 'version' => $input->version])) return null;
            $framework = (new Framework($input->name, $input->version))->setDescription($input->description)->setPublisher($input->publisher)->setStatus($input->status);
            $this->em->persist($framework);
            $items = [];
            foreach ($rows as $row) {
                $item = (new Requirement($framework, $row['reference'], $row['title'], $row['category']))->setDescription($row['description'])->setStatus($row['status']);
                $items['ref:'.$row['reference']] = $item;
                $this->em->persist($item);
            }
            foreach ($rows as $row) {
                if ('' !== $row['parentReference']) $items['ref:'.$row['reference']]->setParentRequirement($items['ref:'.$row['parentReference']]);
            }
            return $framework;
        });
        if (null === $framework) return new JsonResponse(['code' => 'FRAMEWORK_EXISTS', 'message' => 'Cette version vient d’être importée.'], 409);
        return new JsonResponse(['id' => $framework->getId(), 'count' => count($rows)], 201);
    }
}
