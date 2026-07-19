<?php

declare(strict_types=1);

namespace App\Tests\Domain\Risk;

use App\Domain\Risk\RiskCalculation;
use App\Domain\Risk\RiskLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RiskCalculationTest extends TestCase
{
    private const THRESHOLDS = ['lowMax' => 4, 'moderateMax' => 9, 'highMax' => 16, 'criticalMax' => 25];

    #[DataProvider('scores')]
    public function testScoreAndLevel(int $likelihood, int $impact, int $score, RiskLevel $level): void
    {
        $calculation = new RiskCalculation();
        self::assertSame($score, $calculation->score($likelihood, $impact));
        self::assertSame($level, $calculation->level($score, self::THRESHOLDS));
    }

    /** @return iterable<string, array{int, int, int, RiskLevel}> */
    public static function scores(): iterable
    {
        yield 'low upper boundary' => [2, 2, 4, RiskLevel::LOW];
        yield 'moderate lower boundary' => [1, 5, 5, RiskLevel::MODERATE];
        yield 'moderate upper boundary' => [3, 3, 9, RiskLevel::MODERATE];
        yield 'high lower boundary' => [2, 5, 10, RiskLevel::HIGH];
        yield 'high upper boundary' => [4, 4, 16, RiskLevel::HIGH];
        yield 'critical lower boundary' => [4, 5, 20, RiskLevel::CRITICAL];
        yield 'critical maximum' => [5, 5, 25, RiskLevel::CRITICAL];
    }

    public function testInvalidScaleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RiskCalculation())->score(0, 5);
    }

    public function testCustomThresholdsAreUsed(): void
    {
        $level = (new RiskCalculation())->level(8, ['lowMax' => 2, 'moderateMax' => 6, 'highMax' => 12, 'criticalMax' => 25]);
        self::assertSame(RiskLevel::HIGH, $level);
    }
}
