<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\XlsxExporter;
use PHPUnit\Framework\TestCase;

final class XlsxExporterTest extends TestCase
{
    public function testWorkbookIsStyledFilterableAndFormulaSafe(): void
    {
        $content = (new XlsxExporter())->render('Plans d’action', 'Tenant', [
            ['Action', 'Progression'],
            ['=DANGEROUS()', 75],
        ]);
        self::assertStringStartsWith('PK', $content);

        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $styles = $zip->getFromName('xl/styles.xml');
        $zip->close();
        unlink($path);

        self::assertIsString($sheet);
        self::assertStringContainsString('state="frozen"', $sheet);
        self::assertStringContainsString('<autoFilter ref="A3:B4"/>', $sheet);
        self::assertStringContainsString('&apos;=DANGEROUS()', $sheet);
        self::assertIsString($styles);
        self::assertStringContainsString('FF17324D', $styles);
        self::assertStringContainsString('FFEAF2F6', $styles);
    }
}
