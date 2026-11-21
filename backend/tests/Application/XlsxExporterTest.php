<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\XlsxExporter;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertStringContainsString('t="inlineStr"><is><t xml:space="preserve">=DANGEROUS()</t>', $sheet, 'Text cells are never evaluated: no stray apostrophe is added.');
        self::assertStringNotContainsString('&apos;=DANGEROUS()', $sheet);
        self::assertIsString($styles);
        self::assertStringContainsString('FF17324D', $styles);
        self::assertStringContainsString('FFEAF2F6', $styles);
    }

    #[DataProvider('invalidRows')]
    public function testInvalidRowsCannotProduceACorruptWorkbook(array $rows): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new XlsxExporter())->render('Export', 'Tenant', $rows);
    }

    public static function invalidRows(): iterable
    {
        yield 'empty header' => [[[]]];
        yield 'column limit' => [[array_fill(0, 16385, 'Header')]];
        yield 'ragged row' => [[['A', 'B'], ['Only one']]];
        yield 'mapped row' => [[['A'], ['key' => 'value']]];
        yield 'non finite' => [[['A'], [INF]]];
        yield 'cell too long' => [[['A'], [str_repeat('é', 32768)]]];
        yield 'invalid UTF-8' => [[['A'], ["\xFF"]]];
    }

    public function testXmlOrderingSheetNameAndPrintHeaders(): void
    {
        $content = (new XlsxExporter())->render("'Audit:/[]?*'", "R&D\x00", [['A', 'B'], ["text\x00", 2]]);
        $parts = $this->parts($content);
        $sheet = $parts['xl/worksheets/sheet1.xml'];
        self::assertLessThan(strpos($sheet, '<sheetViews>'), strpos($sheet, '<dimension '));
        self::assertLessThan(strpos($sheet, '<mergeCells '), strpos($sheet, '<autoFilter '));
        self::assertStringNotContainsString("\x00", implode('', $parts));
        self::assertStringContainsString('R&amp;&amp;D', $sheet);
        $workbook = simplexml_load_string($parts['xl/workbook.xml']);
        self::assertNotFalse($workbook);
        self::assertSame('Audit', (string) $workbook->sheets->sheet['name']);
        self::assertStringContainsString('_xlnm.Print_Titles', $parts['xl/workbook.xml']);
        self::assertSame("'Audit'!\$1:\$3", (string) $workbook->definedNames->definedName);
        foreach ($parts as $xml) {
            self::assertNotFalse(simplexml_load_string($xml));
        }
        $single = $this->parts((new XlsxExporter())->render('[]', 'Tenant', [['Only column']]));
        self::assertStringNotContainsString('<mergeCells', $single['xl/worksheets/sheet1.xml']);
        self::assertStringContainsString('name="RiskPilot"', $single['xl/workbook.xml']);
    }

    public function testCoreTimestampReallyUsesUtc(): void
    {
        $previous = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Honolulu');
            $parts = $this->parts((new XlsxExporter())->render('Audit', 'Tenant', [['A']]));
            self::assertStringContainsString(gmdate('Y-m-d\TH:i'), $parts['docProps/core.xml']);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testRowLimitIsRejectedBeforeProcessingRows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new XlsxExporter())->render('Export', 'Tenant', array_fill(0, 1048575, ['Header']));
    }

    private function parts(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($path, $content);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        try {
            $parts = [];
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $parts[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
            }

            return $parts;
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function testDatesAreRealDateCellsAndAnInformationSheetDescribesTheExport(): void
    {
        $content = (new XlsxExporter())->render('Plans', 'Tenant', [
            ['Action', 'Échéance', 'Coût'],
            ['A', new \DateTimeImmutable('2026-11-21'), -12.5],
            ['B', new \DateTimeImmutable('2026-11-21 06:00:00'), 3],
            ['-minus', null, 0],
        ], ['Généré par' => 'Alice Admin']);
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($path, $content);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $info = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $core = (string) $zip->getFromName('docProps/core.xml');
        $zip->close();
        unlink($path);

        self::assertStringContainsString('<c r="B4" s="7" t="n"><v>46347</v></c>', $sheet, '2026-11-21 is serial 46347.');
        self::assertStringContainsString('<c r="B5" s="8" t="n"><v>46347.25</v></c>', $sheet);
        self::assertStringContainsString('<c r="C4" s="6" t="n"><v>-12.5</v></c>', $sheet);
        self::assertStringContainsString('>-minus<', $sheet);
        self::assertStringContainsString('name="Informations"', $workbook);
        self::assertStringContainsString('Alice Admin', $info);
        self::assertStringContainsString('<t xml:space="preserve">3</t>', $info);
        self::assertStringContainsString('<dc:creator>Alice Admin</dc:creator>', $core);
    }
}
