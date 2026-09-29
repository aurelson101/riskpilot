<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\PdfReportRenderer;
use PHPUnit\Framework\TestCase;

final class PdfReportRendererTest extends TestCase
{
    public function testEnglishAnnualReportIsSemanticAndKeepsSectionWithItsTable(): void
    {
        $renderer = new PdfReportRenderer();
        $method = new \ReflectionMethod($renderer, 'annualDocument');
        $data = [
            'year' => 2026,
            'version' => 2,
            'organization' => 'Example Ltd',
            'generatedAt' => '2026-08-22T12:00:00+00:00',
            'generatedBy' => ['name' => 'Risk Manager'],
            'period' => ['from' => '2026-01-01', 'until' => '2026-12-31'],
            'totals' => [],
            'byMonth' => [['month' => 1, 'count' => 0]],
            'byDomain' => [],
            'byAction' => [],
            'contributors' => ['Risk Manager' => 1],
            'activities' => [],
            'maturity' => ['assessments' => [], 'weaknesses' => []],
        ];

        $html = $method->invoke($renderer, 'Ignored stored title', $data, 'en');
        self::assertIsString($html);
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('Annual report 2026 — v2', $html);
        self::assertStringContainsString('Executive summary', $html);
        self::assertStringContainsString('<div class="keep"><h3>Contributors</h3><table>', $html);
        $decision = new \ReflectionMethod($renderer, 'decisionDocument');
        $untrustedHtml = $decision->invoke($renderer, '<script>alert(1)</script>', ['organization' => 'Tenant', 'generatedAt' => '2026-08-22T12:00:00+00:00', 'snapshot' => []], 'en');
        self::assertStringContainsString('CONFIDENTIAL', $untrustedHtml);
        self::assertStringNotContainsString('<script>alert(1)</script>', $untrustedHtml);
        $selectedBlocks = $decision->invoke($renderer, 'Board report', ['organization' => 'Tenant', 'generatedAt' => '2026-08-22T12:00:00+00:00', 'blocks' => ['risks'], 'snapshot' => []], 'en');
        self::assertStringContainsString('Priority risks', $selectedBlocks);
        self::assertStringNotContainsString('Priority action plans', $selectedBlocks);
        self::assertStringContainsString('Table of contents', $selectedBlocks);
        $orderedBlocks = $decision->invoke($renderer, 'Board report', ['organization' => 'Tenant', 'generatedAt' => '2026-08-22T12:00:00+00:00', 'blocks' => ['actions', 'risks'], 'snapshot' => []], 'en');
        self::assertLessThan(strpos($orderedBlocks, 'Priority risks'), strpos($orderedBlocks, 'Priority action plans'));
    }

    public function testFrozenInputProducesByteStablePdfAndDocumentMetadata(): void
    {
        $renderer = new PdfReportRenderer();
        $data = ['organization' => 'Example Ltd', 'generatedAt' => '2026-08-22T12:00:00+00:00', 'snapshot' => [], 'blocks' => []];
        $first = $renderer->renderDecisionReport('Board report', $data, 'en');
        $second = $renderer->renderDecisionReport('Board report', $data, 'en');

        self::assertSame($first, $second);
        self::assertStringStartsWith('%PDF-', $first);
        self::assertStringContainsString('/Subject', $first);
        self::assertStringContainsString('/Keywords', $first);
    }

    public function testDetailedExportKeepsEveryFieldAndEveryRecord(): void
    {
        $renderer = new PdfReportRenderer();
        $method = new \ReflectionMethod($renderer, 'dataExportDocument');
        $html = $method->invoke($renderer, 'Registre des risques', [
            'organization' => 'Example Ltd',
            'generatedAt' => '2026-09-29T10:00:00+00:00',
            'generatedBy' => 'Risk Manager',
            'rows' => [
                ['ID', 'Scénario', 'Description', 'Score résiduel'],
                [1, 'Rançongiciel', 'Interruption des opérations', 12],
                [2, 'Fuite de données', null, 8],
            ],
        ], 'fr');

        self::assertIsString($html);
        self::assertStringContainsString('2</strong> enregistrement(s) exporté(s)', $html);
        self::assertStringContainsString('Rançongiciel', $html);
        self::assertStringContainsString('Fuite de données', $html);
        self::assertStringContainsString('Score résiduel', $html);
        self::assertStringContainsString('Non renseigné', $html);
    }
}
