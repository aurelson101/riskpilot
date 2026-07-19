<?php

declare(strict_types=1);

namespace App\Api\Dto;

use App\Entity\Asset;
use Symfony\Component\Validator\Constraints as Assert;

final class AssetInput
{
    #[Assert\NotBlank] #[Assert\Length(max: 180)] public string $name = '';
    #[Assert\Length(max: 5000)] public ?string $description = null;
    #[Assert\Choice(choices: Asset::TYPES)] public string $type = 'OTHER';
    #[Assert\Range(min: 1, max: 5)] public int $criticality = 1;
    #[Assert\Range(min: 1, max: 5)] public int $confidentiality = 1;
    #[Assert\Range(min: 1, max: 5)] public int $integrity = 1;
    #[Assert\Range(min: 1, max: 5)] public int $availability = 1;
    #[Assert\Positive] public ?int $ownerId = null;
    #[Assert\NotNull] #[Assert\Positive] public ?int $scopeId = null;
    /** @var list<int> */ #[Assert\All([new Assert\Positive()])] public array $relatedAssetIds = [];
    #[Assert\Choice(choices: Asset::STATUSES)] public string $status = 'ACTIVE';
}
