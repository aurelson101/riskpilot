<?php

declare(strict_types=1);

namespace App\Tests\Domain\Compliance;

use App\Domain\Compliance\StarterFrameworkCatalog;
use PHPUnit\Framework\TestCase;

final class StarterFrameworkCatalogTest extends TestCase
{
    public function testGovernedPacksHaveUniqueRequirementsAndNoProtectedText(): void
    {
        $catalog = new StarterFrameworkCatalog();
        self::assertSame(['rgpd', 'nis2', 'iso27001', 'ebios-rm', 'iso42001', 'tisax'], $catalog->keys());
        foreach ($catalog->keys() as $key) {
            $definition = $catalog->definition($key);
            self::assertNotEmpty($definition['publisher']);
            self::assertNotEmpty($definition['requirements']);
            $references = array_column($definition['requirements'], 0);
            self::assertCount(count(array_unique($references)), $references, $key.' contains a duplicate reference');
            foreach ($definition['requirements'] as $item) {
                self::assertLessThanOrEqual(100, strlen($item[0]));
                self::assertLessThanOrEqual(255, strlen($item[1]));
                self::assertLessThanOrEqual(120, strlen($item[2]));
                self::assertLessThan(200, strlen($item[1]), 'A title that long looks like reproduced normative text.');
                if (isset($item[3])) {
                    self::assertContains($item[3], $references, sprintf('%s: unknown parent %s for %s', $key, $item[3], $item[0]));
                    self::assertNotSame($item[0], $item[3]);
                }
            }
        }
        foreach (['iso27001', 'iso42001'] as $key) {
            self::assertStringContainsString('Aucun texte protégé', $catalog->definition($key)['description']);
        }
    }

    public function testIso27001ContainsTheFullAnnexAAndTheSubClauses(): void
    {
        $references = array_column((new StarterFrameworkCatalog())->definition('iso27001')['requirements'], 0);
        $controls = array_values(array_filter($references, static fn (string $reference): bool => str_starts_with($reference, 'A.')));
        self::assertCount(93, $controls);
        foreach (['A.5' => 37, 'A.6' => 8, 'A.7' => 14, 'A.8' => 34] as $theme => $expected) {
            self::assertCount($expected, array_filter($controls, static fn (string $reference): bool => str_starts_with($reference, $theme.'.')), $theme);
        }
        foreach (['CLAUSE-6.1.2', 'CLAUSE-6.1.3', 'CLAUSE-9.2', 'CLAUSE-9.3', 'CLAUSE-10.2'] as $clause) {
            self::assertContains($clause, $references);
        }
        self::assertContains('ANNEX-A', $references, 'Existing installations keep their ANNEX-A row as parent.');
    }

    public function testIso42001CoversTheManagementSystemAndTheThirtyEightControls(): void
    {
        $definition = (new StarterFrameworkCatalog())->definition('iso42001');
        $references = array_column($definition['requirements'], 0);
        self::assertCount(38, array_filter($references, static fn (string $reference): bool => str_starts_with($reference, 'A.')));
        self::assertContains('CLAUSE-6.1.4', $references, 'AI system impact assessment clause.');
        self::assertContains('A.5.2', $references);
        self::assertContains('A.6.2.8', $references);
        self::assertSame('ISO/IEC 42001', $definition['name']);
    }

    public function testTisaxDeclaresItsStructureAndPointsToTheOfficialCatalogForControls(): void
    {
        $definition = (new StarterFrameworkCatalog())->definition('tisax');
        $categories = array_unique(array_column($definition['requirements'], 2));
        foreach (['Sécurité de l’information', 'Protection des prototypes', 'Protection des données'] as $module) {
            self::assertContains($module, $categories);
        }
        self::assertStringContainsString('import CSV', $definition['description']);
    }

    public function testTisaxControlQuestionsAttachToTheirSectionsAndEveryModuleHasSome(): void
    {
        $requirements = (new StarterFrameworkCatalog())->definition('tisax')['requirements'];
        $references = array_column($requirements, 0);
        self::assertSame(array_unique($references), $references, 'References are unique.');
        $questions = array_filter($requirements, static fn (array $row): bool => 1 === preg_match('/^\d+\.\d+\.\d+$/', $row[0]));
        self::assertCount(58, $questions);
        foreach ($questions as $question) {
            self::assertContains($question[3] ?? null, $references, $question[0].' has a known parent section.');
            self::assertStringStartsWith($question[3].'.', $question[0]);
        }
        self::assertSame(['Sécurité de l’information', 'Protection des prototypes', 'Protection des données'], array_values(array_unique(array_column($questions, 2))));
    }

    public function testNis2DetailsArticle21AndTheArticle23Timeline(): void
    {
        $references = array_column((new StarterFrameworkCatalog())->definition('nis2')['requirements'], 0);
        foreach (['ART-21.2-A', 'ART-21.2-H-J', 'ART-21.2-H', 'ART-21.2-I', 'ART-21.2-J', 'ART-21.3', 'ART-23', 'ART-23.4-A', 'ART-23.4-B', 'ART-23.4-C', 'ART-23.4-D'] as $reference) {
            self::assertContains($reference, $references);
        }
    }

    public function testInstantiateLinksChildrenToTheirParentInTheSameFramework(): void
    {
        [$framework, $requirements] = (new StarterFrameworkCatalog())->instantiate('iso27001');
        $byReference = [];
        foreach ($requirements as $requirement) {
            $byReference[$requirement->getReference()] = $requirement;
        }
        self::assertSame($byReference['ANNEX-A'], $byReference['A.8.24']->getParentRequirement());
        self::assertSame($byReference['CLAUSE-6'], $byReference['CLAUSE-6.1.2']->getParentRequirement());
        self::assertNull($byReference['CLAUSE-6']->getParentRequirement());
        self::assertSame($framework, $byReference['A.8.24']->getFramework());
    }

    public function testMissingRequirementsOnlyReturnsWhatAnInstalledFrameworkLacks(): void
    {
        $catalog = new StarterFrameworkCatalog();
        [$framework, $requirements] = $catalog->instantiate('nis2');
        $legacy = [];
        foreach ($requirements as $requirement) {
            if (in_array($requirement->getReference(), ['ART-20', 'ART-21.2-A', 'ART-21.2-H-J', 'ART-23'], true)) {
                $legacy[$requirement->getReference()] = $requirement;
            }
        }

        $missing = $catalog->missingRequirements('nis2', $framework, $legacy);
        $references = array_map(static fn ($requirement): string => $requirement->getReference(), $missing);

        self::assertCount(count($requirements) - 4, $missing);
        self::assertNotContains('ART-20', $references);
        $byReference = array_combine($references, $missing);
        self::assertSame($legacy['ART-21.2-H-J'], $byReference['ART-21.2-H']->getParentRequirement(), 'A new child attaches to the requirement that already exists.');
        self::assertSame($legacy['ART-23'], $byReference['ART-23.4-A']->getParentRequirement());
    }
}
