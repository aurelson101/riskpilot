<?php

declare(strict_types=1);

namespace App\Tests\Domain\Compliance;

use App\Domain\Compliance\StarterFrameworkCatalog;
use PHPUnit\Framework\TestCase;

final class StarterFrameworkCatalogTest extends TestCase
{
    public function testGovernedPacksHaveUniqueRequirementsAndIsoIsMetadataOnly(): void
    {
        $catalog = new StarterFrameworkCatalog();
        self::assertSame(['rgpd', 'nis2', 'iso27001', 'ebios-rm'], $catalog->keys());
        foreach ($catalog->keys() as $key) {
            $definition = $catalog->definition($key);
            self::assertNotEmpty($definition['publisher']);
            self::assertNotEmpty($definition['requirements']);
            $references = array_column($definition['requirements'], 0);
            self::assertCount(count(array_unique($references)), $references);
        }
        self::assertStringContainsString('Aucun texte protégé', $catalog->definition('iso27001')['description']);
    }
}
