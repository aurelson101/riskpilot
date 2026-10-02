<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\ActionPlan;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ActionPlanInput
{
    #[Assert\NotBlank, Assert\Length(max: 255)] public string $title = '';
    #[Assert\Length(max: 10000)] public ?string $description = null;
    #[Assert\Positive] public ?int $relatedRiskId = null;
    #[Assert\Positive] public ?int $relatedControlId = null;
    #[Assert\NotNull, Assert\Positive] public ?int $ownerId = null;
    #[Assert\Choice(choices: ActionPlan::PRIORITIES)] public string $priority = 'MEDIUM';
    #[Assert\Choice(choices: ActionPlan::STATUSES)] public string $status = 'OPEN';
    #[Assert\Date] public ?string $startDate = null;
    #[Assert\NotBlank, Assert\Date] public ?string $dueDate = null;
    #[Assert\Date] public ?string $completionDate = null;
    #[Assert\Range(min: 0, max: 100)] public int $progress = 0;
    #[Assert\Range(min: 0, max: 9999999999.99)] public ?float $estimatedCost = null;
    #[Assert\Range(min: 0, max: 999999.99)] public ?float $estimatedEffortDays = null;
    #[Assert\Range(min: 0, max: 9999999999.99)] public ?float $actualCost = null;
    #[Assert\Range(min: 0, max: 25)] public ?int $expectedRiskReduction = null;
    /** @var list<string> */
    #[Assert\Count(max: 100), Assert\All([new Assert\Url(requireTld: false), new Assert\Length(max: 2048)])] public array $evidence = [];
    #[Assert\Length(max: 120)] public ?string $ticketNumber = null;
    #[Assert\Url(requireTld: false), Assert\Length(max: 2048)] public ?string $ticketUrl = null;
    #[Assert\Choice(choices: ActionPlan::ORIGINS)] public string $origin = 'OTHER';
    #[Assert\Choice(choices: ActionPlan::ACTION_TYPES)] public string $actionType = 'OTHER';
    /** @var list<int> */ #[Assert\Count(max: 500), Assert\All([new Assert\Type('integer'), new Assert\Positive()])] public array $frameworkIds = [];
    /** @var list<int> */ #[Assert\Count(max: 500), Assert\All([new Assert\Type('integer'), new Assert\Positive()])] public array $requirementIds = [];
    /** @var array<string, scalar|null> */ public array $customFields = [];
    /** @var list<array{type: string, id: int}> */ #[Assert\Count(max: 100)] public array $nonConformities = [];

    #[Assert\Callback]
    public function validateCollections(ExecutionContextInterface $context): void
    {
        foreach (['frameworkIds', 'requirementIds', 'evidence', 'nonConformities'] as $field) {
            if (!array_is_list($this->{$field})) {
                $context->buildViolation('Une liste est attendue.')->atPath($field)->addViolation();
            }
        }
        foreach ($this->nonConformities as $link) {
            if (!is_array($link) || count($link) !== 2 || !in_array($link['type'] ?? null, ['AUDIT_FINDING', 'COMPLIANCE_RESULT'], true) || !is_int($link['id'] ?? null) || $link['id'] < 1) {
                $context->buildViolation('La non-conformité doit préciser un type et un identifiant positif.')->atPath('nonConformities')->addViolation();
                break;
            }
        }
        if (count($this->customFields) > 100) {
            $context->buildViolation('Au maximum 100 champs personnalisés.')->atPath('customFields')->addViolation();
        }
        foreach ($this->customFields as $key => $value) {
            if (!is_string($key) || '' === $key || strlen($key) > 120 || (!is_scalar($value) && null !== $value) || (is_string($value) && mb_strlen($value) > 10000) || (is_float($value) && !is_finite($value))) {
                $context->buildViolation('Champ personnalisé invalide.')->atPath('customFields')->addViolation();
                break;
            }
        }
        foreach (['estimatedCost', 'actualCost', 'estimatedEffortDays'] as $field) {
            if (null !== $this->{$field} && !is_finite($this->{$field})) {
                $context->buildViolation('Une valeur finie est attendue.')->atPath($field)->addViolation();
            }
        }
        if (null !== $this->completionDate && null !== $this->startDate && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->completionDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $this->startDate) && $this->completionDate < $this->startDate) {
            $context->buildViolation('La fin ne peut pas précéder le début.')->atPath('completionDate')->addViolation();
        }
    }
}
