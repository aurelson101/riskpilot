<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\Dto\AssetInput;
use App\Api\Dto\VulnerabilityInput;
use App\Api\Dto\RiskScenarioInput;
use App\Api\Dto\EvidenceInput;
use App\Api\Dto\ComplianceResultInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class GrcCollectionInputTest extends TestCase
{
    #[DataProvider('invalidCollections')]
    public function testCollectionErrorsAreReportedAtTheRelevantField(string $class, string $field, array $value): void
    {
        $input = new $class();
        $input->{$field} = $value;
        $errors = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($input);
        self::assertNotEmpty(array_filter(iterator_to_array($errors), static fn ($error) => str_starts_with($error->getPropertyPath(), $field)));
    }

    public static function invalidCollections(): iterable
    {
        foreach ([AssetInput::class => ['relatedAssetIds'], VulnerabilityInput::class => ['affectedAssetIds'],
            RiskScenarioInput::class => ['vulnerabilityIds', 'currentControlIds'], EvidenceInput::class => ['requirementIds', 'controlIds', 'resultIds', 'actionIds']] as $class => $fields) {
            foreach ($fields as $field) {
                yield $class.' '.$field.' type' => [$class, $field, ['1']];
                yield $class.' '.$field.' shape' => [$class, $field, ['key' => 1]];
                yield $class.' '.$field.' count' => [$class, $field, range(1, 501)];
            }
        }
        yield 'proof URL count' => [ComplianceResultInput::class, 'evidence', array_fill(0, 101, 'https://example.test/proof')];
        yield 'proof URL length' => [ComplianceResultInput::class, 'evidence', ['https://example.test/'.str_repeat('a', 2048)]];
        yield 'proof URL shape' => [ComplianceResultInput::class, 'evidence', ['url' => 'https://example.test/proof']];
        yield 'method nested value' => [RiskScenarioInput::class, 'methodData', ['businessValue' => ['nested']]];
        yield 'method oversized value' => [RiskScenarioInput::class, 'methodData', ['businessValue' => str_repeat('a', 5001)]];
        yield 'method count' => [RiskScenarioInput::class, 'methodData', array_fill_keys(array_map(strval(...), range(1, 101)), 'value')];
    }
}
