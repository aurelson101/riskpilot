<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\ActionCustomField;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/action-custom-fields')]
final readonly class ActionCustomFieldController
{
    public function __construct(private CurrentUser $currentUser, private EntityManagerInterface $em) {}
    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse { return new JsonResponse(array_map($this->serialize(...), $this->em->getRepository(ActionCustomField::class)->findBy(['organization' => $this->currentUser->get()->getOrganization()], ['order' => 'ASC']))); }
    #[Route('', methods: ['POST'])] #[IsGranted(User::ROLE_ADMIN)]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray(); $key = trim((string) ($data['key'] ?? '')); $label = trim((string) ($data['label'] ?? '')); $type = (string) ($data['type'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{1,79}$/', $key) || '' === $label || !in_array($type, ActionCustomField::TYPES, true)) return new JsonResponse(['code' => 'VALIDATION_ERROR', 'message' => 'Invalid key, label or type.'], 422);
        $item = (new ActionCustomField($this->currentUser->get()->getOrganization(), $key, $label, $type))->configure($label, $type, array_values(array_filter($data['options'] ?? [], 'is_string')), (int) ($data['order'] ?? 0), (bool) ($data['visible'] ?? true), (bool) ($data['required'] ?? false));
        $this->em->persist($item); $this->em->flush();
        return new JsonResponse($this->serialize($item), 201);
    }
    /** @return array<string, mixed> */
    private function serialize(ActionCustomField $item): array { return ['id' => $item->getId(), 'key' => $item->getKey(), 'label' => $item->getLabel(), 'type' => $item->getType(), 'options' => $item->getOptions(), 'order' => $item->getOrder(), 'visible' => $item->isVisible(), 'required' => $item->isRequired()]; }
}
