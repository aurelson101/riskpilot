<?php

declare(strict_types=1);

namespace App\Tests\Domain\Risk;

use App\Domain\Risk\RiskMethodValidator;
use PHPUnit\Framework\TestCase;

final class RiskMethodValidatorTest extends TestCase
{
    public function testEbiosRequiresItsFiveCoreLinks(): void
    {
        $validator = new RiskMethodValidator();
        $this->expectException(\InvalidArgumentException::class);
        $validator->validate('EBIOS_RM', ['businessValue' => 'Production']);
    }

    public function testIsoDataIsNormalized(): void
    {
        $validator = new RiskMethodValidator();
        self::assertSame(['likelihoodRationale' => 'Fréquence observée', 'impactRationale' => 'Arrêt métier', 'controlRationale' => 'PRA testé'], $validator->validate('ISO_27005', ['likelihoodRationale' => ' Fréquence observée ', 'impactRationale' => 'Arrêt métier', 'controlRationale' => 'PRA testé', 'ignored' => '']));
    }
}
