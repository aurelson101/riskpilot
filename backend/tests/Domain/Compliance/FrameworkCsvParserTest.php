<?php

declare(strict_types=1);

namespace App\Tests\Domain\Compliance;

use App\Domain\Compliance\FrameworkCsvParser;
use App\Entity\Framework;
use App\Entity\Requirement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrameworkCsvParserTest extends TestCase
{
    private const HEADER = "reference,title,category,description,parentReference\n";

    public function testExportRoundTripEscapingAndHierarchy(): void
    {
        $framework = new Framework('Local', '1');
        $root = new Requirement($framework, '=ROOT', 'Root, access', 'Security');
        $child = (new Requirement($framework, 'CHILD', 'Child', 'Security'))->setParentRequirement($root)->setDescription("Line one\nLine two")->setStatus('ARCHIVED');
        $parser = new FrameworkCsvParser();
        $csv = $parser->export([$child, $root]);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = $parser->parse($csv);
        self::assertCount(2, $rows);
        self::assertSame("'=ROOT", $rows[1]['reference']);
        self::assertSame($rows[1]['reference'], $rows[0]['parentReference']);
        self::assertSame("Line one\nLine two", $rows[0]['description']);
        self::assertSame('Root, access', $rows[1]['title']);
        self::assertSame('ARCHIVED', $rows[0]['status']);
        self::assertSame('ACTIVE', $rows[1]['status']);
    }

    public function testSemicolonCrLfQuotedDescriptionAndStatuses(): void
    {
        $csv = "reference;title;category;description;parentReference;status\r\n01;Racine;Sécurité;\"Virgule, point-virgule; texte\";;INACTIVE\r\n02;Enfant;Sécurité;;01;ARCHIVED\r\n";
        $rows = (new FrameworkCsvParser())->parse($csv);
        self::assertSame('01', $rows[0]['reference']);
        self::assertSame('01', $rows[1]['parentReference']);
        self::assertSame('Virgule, point-virgule; texte', $rows[0]['description']);
        self::assertSame('INACTIVE', $rows[0]['status']);
        self::assertSame('ARCHIVED', $rows[1]['status']);
    }

    public function testLegacyFiveColumnCsvDefaultsToActive(): void
    {
        foreach ([',', ';'] as $separator) {
            $csv = str_replace(',', $separator, self::HEADER."A,Title,Security,,\n");
            self::assertSame('ACTIVE', (new FrameworkCsvParser())->parse($csv)[0]['status']);
        }
    }

    public function testExportRefusesReferenceCollisionAfterFormulaEscaping(): void
    {
        $framework = new Framework('Local', '1');
        $this->expectException(\InvalidArgumentException::class);
        (new FrameworkCsvParser())->export([new Requirement($framework, '=ROOT', 'Root', 'Security'), new Requirement($framework, "'=ROOT", 'Other', 'Security')]);
    }

    public function testExportRefusesEmptyFramework(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FrameworkCsvParser())->export([]);
    }

    public function testExportRefusesPartialFileOnRowLimit(): void
    {
        $framework = new Framework('Local', '1'); $items = [];
        for ($i = 0; $i < 501; ++$i) $items[] = new Requirement($framework, (string) $i, 'Title', 'Security');
        $this->expectException(\InvalidArgumentException::class);
        (new FrameworkCsvParser())->export($items);
    }

    public function testBomQuotedMultilineAndForwardParent(): void
    {
        $rows = (new FrameworkCsvParser())->parse("\xEF\xBB\xBF".self::HEADER."1.1,Child,Security,\"A, description\ncontinued\",1\n1,Root,Security,,\n");
        self::assertCount(2, $rows);
        self::assertSame('1', $rows[0]['parentReference']);
        self::assertSame("A, description\ncontinued", $rows[0]['description']);
    }

    #[DataProvider('invalidCsv')]
    public function testInvalidCsv(string $csv): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FrameworkCsvParser())->parse($csv);
    }

    public static function invalidCsv(): iterable
    {
        yield 'empty' => [''];
        yield 'no rows' => [self::HEADER];
        yield 'wrong header' => ["title,reference\nA,B\n"];
        yield 'missing column' => [self::HEADER."A,Title,Security,\n"];
        yield 'blank required' => [self::HEADER."A,,Security,,\n"];
        yield 'duplicate' => [self::HEADER."A,Title,Security,,\nA,Other,Security,,\n"];
        yield 'missing parent' => [self::HEADER."A,Title,Security,,B\n"];
        yield 'self cycle' => [self::HEADER."A,Title,Security,,A\n"];
        yield 'cycle' => [self::HEADER."A,Title,Security,,B\nB,Title,Security,,A\n"];
        yield 'control character' => [self::HEADER."A,Title,Security,\x00,\n"];
        yield 'invalid utf8' => [self::HEADER."A,Title,Security,\xFF,\n"];
        yield 'long reference' => [self::HEADER.str_repeat('A', 101).",Title,Security,,\n"];
        yield 'blank explicit status' => ["reference,title,category,description,parentReference,status\nA,Title,Security,,,\n"];
        yield 'unknown status' => ["reference,title,category,description,parentReference,status\nA,Title,Security,,,DELETED\n"];
        yield 'mixed delimiters' => ["reference;title;category;description;parentReference\nA,Title,Security,,\n"];
    }

    public function testRowLimit(): void
    {
        $csv = self::HEADER;
        for ($i = 0; $i < 501; ++$i) $csv .= "$i,Title,Security,,\n";
        $this->expectException(\InvalidArgumentException::class);
        (new FrameworkCsvParser())->parse($csv);
    }

    public function testByteLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FrameworkCsvParser())->parse(str_repeat('A', FrameworkCsvParser::MAX_BYTES + 1));
    }
}
