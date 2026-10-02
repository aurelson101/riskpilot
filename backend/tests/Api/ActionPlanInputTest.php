<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\Dto\ActionPlanInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ActionPlanInputTest extends TestCase
{
    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreReportedBeforePersistence(string $field, mixed $value): void
    {
        $input = $this->validInput();
        $input->{$field} = $value;
        $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($input);
        self::assertNotEmpty($violations);
        self::assertNotEmpty(array_filter(iterator_to_array($violations), static fn ($violation) => str_starts_with($violation->getPropertyPath(), $field)));
    }

    public static function invalidValues(): iterable
    {
        yield 'estimated cost' => ['estimatedCost', 10000000000.0];
        yield 'actual cost' => ['actualCost', 10000000000.0];
        yield 'effort' => ['estimatedEffortDays', 1000000.0];
        yield 'non finite' => ['estimatedCost', INF];
        yield 'evidence count' => ['evidence', array_fill(0, 101, 'https://evidence.example.test')];
        yield 'evidence length' => ['evidence', ['https://example.test/'.str_repeat('a', 2048)]];
        yield 'framework string' => ['frameworkIds', ['1']];
        yield 'framework map' => ['frameworkIds', ['a' => 1]];
        yield 'requirement string' => ['requirementIds', ['1']];
        yield 'requirement limit' => ['requirementIds', range(1, 501)];
        yield 'missing non conformity fields' => ['nonConformities', [[]]];
        yield 'unknown non conformity type' => ['nonConformities', [['type' => 'OTHER', 'id' => 1]]];
        yield 'non conformity wrong id' => ['nonConformities', [['type' => 'AUDIT_FINDING', 'id' => '1']]];
        yield 'non conformity count' => ['nonConformities', array_fill(0, 101, ['type' => 'AUDIT_FINDING', 'id' => 1])];
        yield 'nested custom field' => ['customFields', ['owner' => ['secret' => 'x']]];
        yield 'custom fields count' => ['customFields', array_combine(range(1, 101), range(1, 101))];
        yield 'completion chronology' => ['completionDate', '2029-12-31'];
    }

    public function testValidGovernedActionRemainsAccepted(): void
    {
        $input = $this->validInput();
        $input->estimatedCost = 1200.50;
        $input->customFields = ['department' => 'Security', 'approved' => true];
        $input->nonConformities = [['type' => 'AUDIT_FINDING', 'id' => 1]];
        self::assertCount(0, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($input));
    }

    private function validInput(): ActionPlanInput
    {
        $input = new ActionPlanInput();
        $input->title = 'Review access';
        $input->ownerId = 1;
        $input->startDate = '2030-01-01';
        $input->dueDate = '2030-02-01';

        return $input;
    }
}
