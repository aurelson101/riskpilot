<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Risk\EbiosWorkshopValidator;
use App\Entity\EbiosWorkshop;
use App\Entity\Organization;
use App\Entity\RiskAnalysis;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class EbiosWorkshopTest extends TestCase
{
    public function testWorkshopRequiresCompleteDataAndIndependentValidation(): void
    {
        $organization = new Organization('Tenant');
        $owner = new User('owner@example.test', 'Risk', 'Owner', $organization);
        $validator = new User('validator@example.test', 'Audit', 'Validator', $organization);
        $analysis = new RiskAnalysis($organization, $owner, 'ebios.core', 1, 'EBIOS_RM', 'Analyse EBIOS');
        $workshop = new EbiosWorkshop($analysis, $owner, 1);
        $payload = ['context' => 'Périmètre', 'businessValues' => ['ERP'], 'supportingAssets' => ['Serveur'], 'dreadedEvents' => ['Indisponibilité'], 'securityBaseline' => ['MFA']];
        $workshop->update($payload, $owner);

        self::assertSame([], (new EbiosWorkshopValidator())->violations(1, $payload));
        $workshop->validate($validator);
        self::assertSame('VALIDATED', $workshop->getStatus());
        self::assertSame($validator, $workshop->getValidatedBy());
    }
}
