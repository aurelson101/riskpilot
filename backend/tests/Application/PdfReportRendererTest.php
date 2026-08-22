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
}
