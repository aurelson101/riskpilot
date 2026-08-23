<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\Evidence;
use Symfony\Component\Validator\Constraints as Assert;

final class EvidenceInput
{
    #[Assert\NotBlank, Assert\Length(max: 220)] public ?string $title = null;
    #[Assert\Choice(choices: Evidence::KINDS)] public string $kind = 'EXTERNAL_REFERENCE';
    #[Assert\Choice(choices: Evidence::CLASSIFICATIONS)] public string $classification = 'INTERNAL';
    #[Assert\NotBlank, Assert\Length(max: 2048)] public ?string $sourceReference = null;
    #[Assert\Regex(pattern: '/^[a-f0-9]{64}$/')] public string $sha256 = '';
    #[Assert\Date] public ?string $validFrom = null;
    #[Assert\Date] public ?string $expiresAt = null;
    /** @var list<int> */ #[Assert\All([new Assert\Positive()])] public array $requirementIds = [];
    /** @var list<int> */ #[Assert\All([new Assert\Positive()])] public array $controlIds = [];
    /** @var list<int> */ #[Assert\All([new Assert\Positive()])] public array $resultIds = [];
    /** @var list<int> */ #[Assert\All([new Assert\Positive()])] public array $actionIds = [];
}
