<?php

declare(strict_types=1);

namespace App\Tests\Domain\Compliance;

use App\Domain\Compliance\StarterFrameworkCatalog;
use App\Domain\Compliance\StarterMappingCatalog;
use PHPUnit\Framework\TestCase;

final class StarterMappingCatalogTest extends TestCase
{
    public function testEverySuggestedMappingPointsToExistingRequirementsAndIsMarkedForReview(): void
    {
        $packs = new StarterFrameworkCatalog();
        $known = [];
        foreach ($packs->keys() as $key) {
            $known[$key] = array_flip(array_column($packs->definition($key)['requirements'], 0));
        }
        $mappings = (new StarterMappingCatalog())->mappings();
        self::assertGreaterThan(100, count($mappings));
        $pairs = [];
        foreach ($mappings as $mapping) {
            foreach (['source', 'target'] as $side) {
                self::assertArrayHasKey($mapping[$side][1], $known[$mapping[$side][0]], sprintf('%s %s is not in the %s pack.', $side, $mapping[$side][1], $mapping[$side][0]));
            }
            self::assertNotSame($mapping['source'], $mapping['target']);
            self::assertGreaterThanOrEqual(1, $mapping['coverage']);
            self::assertLessThan(100, $mapping['coverage'], 'A suggestion is never a full equivalence.');
            self::assertStringStartsWith(StarterMappingCatalog::RATIONALE_PREFIX, $mapping['rationale']);
            $pair = json_encode([$mapping['source'], $mapping['target']], JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey($pair, $pairs, 'Duplicate suggestion '.$pair);
            $pairs[$pair] = true;
        }
    }
}
