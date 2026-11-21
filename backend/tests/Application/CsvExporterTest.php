<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\CsvExporter;
use PHPUnit\Framework\TestCase;

final class CsvExporterTest extends TestCase
{
    public function testExcelFriendlyOutputKeepsNumbersAndDatesAndNeutralisesFormulas(): void
    {
        $csv = (new CsvExporter())->render([
            ['Nom', 'Montant', 'Date', 'Note', 'Actif'],
            ['=HYPERLINK("http://evil")', -12.5, new \DateTimeImmutable('2026-11-21'), '  +cmd|calc', true],
            ['@SUM(1)', 0, null, "\t=1+1", false],
            ['-5', '+33', 'Ligne "citée"; avec séparateur', "multi\nligne", 'x'],
        ]);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\r\n", substr($csv, 3));
        self::assertSame('Nom;Montant;Date;Note;Actif', $lines[0]);
        self::assertStringStartsWith("\"'=HYPERLINK(\"\"http://evil\"\")\";-12.5;21/11/2026;\"'  +cmd|calc\";1", $lines[1]);
        self::assertStringStartsWith("'@SUM(1);0;;\"'\t=1+1\";0", $lines[2]);
        self::assertStringContainsString('-5;+33;"Ligne ""citée""; avec séparateur"', $lines[3], 'Numeric-looking text is not altered.');
    }

    public function testDelimiterDateFormatAndHeaders(): void
    {
        $exporter = new CsvExporter();
        self::assertSame("a,b\r\n2026-11-21,x\r\n", substr($exporter->render([['a', 'b'], [new \DateTimeImmutable('2026-11-21'), 'x']], ',', 'Y-m-d'), 3));
        $response = $exporter->response([['a']], 'my report é.csv', 'en');
        self::assertSame('en', $response->headers->get('Content-Language'));
        self::assertSame('attachment; filename="my-report-.csv"', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
