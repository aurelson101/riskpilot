<?php

declare(strict_types=1);

namespace App\Application;

final class XlsxExporter
{
    /**
     * @param list<list<int|float|string|null>> $rows
     */
    public function render(string $title, string $organization, array $rows): string
    {
        if ([] === $rows) {
            throw new \InvalidArgumentException('An XLSX export requires a header row.');
        }
        $columns = count($rows[0]);
        if (0 === $columns || $columns > 16384 || count($rows) > 1048574) {
            throw new \InvalidArgumentException('Export exceeds worksheet dimensions.');
        }
        foreach ($rows as $row) {
            if (!array_is_list($row) || count($row) !== $columns) {
                throw new \InvalidArgumentException('Export rows must match the header columns.');
            }
            foreach ($row as $value) {
                if ((!is_scalar($value) && null !== $value) || is_bool($value) || (is_float($value) && !is_finite($value)) || (is_string($value) && !mb_check_encoding($value, 'UTF-8'))) {
                    throw new \InvalidArgumentException('Invalid export cell value.');
                }
                if (mb_strlen((string) $value) > 32767) {
                    throw new \InvalidArgumentException('Export cell exceeds the Excel text limit.');
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'riskpilot-xlsx-');
        if (false === $path) {
            throw new \RuntimeException('Unable to create XLSX export.');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            @unlink($path);
            throw new \RuntimeException('Unable to create XLSX archive.');
        }

        $lastColumn = $this->columnName($columns);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('docProps/app.xml', $this->appProperties());
        $zip->addFromString('docProps/core.xml', $this->coreProperties($title));
        $zip->addFromString('xl/workbook.xml', $this->workbook($title));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet($title, $organization, $rows, $lastColumn));
        $zip->close();

        $content = file_get_contents($path);
        @unlink($path);
        if (false === $content) {
            throw new \RuntimeException('Unable to read XLSX export.');
        }

        return $content;
    }

    /** @param list<list<int|float|string|null>> $rows */
    private function worksheet(string $title, string $organization, array $rows, string $lastColumn): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        $xml .= sprintf('<dimension ref="A1:%s%d"/>', $lastColumn, count($rows) + 2);
        $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $xml .= '<cols>';
        foreach ($rows[0] as $index => $header) {
            $width = $this->columnWidth($rows, $index);
            $xml .= sprintf('<col min="%d" max="%d" width="%d" customWidth="1"/>', $index + 1, $index + 1, $width);
        }
        $xml .= '</cols><sheetData>';
        $xml .= '<row r="1" ht="28" customHeight="1">'.$this->inlineCell('A1', $title, 1).'</row>';
        $xml .= '<row r="2" ht="22" customHeight="1">'.$this->inlineCell('A2', sprintf('%s · %s', $organization, (new \DateTimeImmutable())->format('d/m/Y H:i')), 2).'</row>';
        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 3;
            $style = 0 === $rowIndex ? 3 : (0 === $rowIndex % 2 ? 4 : 5);
            $xml .= sprintf('<row r="%d"%s>', $excelRow, 0 === $rowIndex ? ' ht="24" customHeight="1"' : '');
            foreach ($row as $columnIndex => $value) {
                $ref = $this->columnName($columnIndex + 1).$excelRow;
                if (0 !== $rowIndex && (is_int($value) || is_float($value))) {
                    $xml .= sprintf('<c r="%s" s="6" t="n"><v>%s</v></c>', $ref, $value);
                } else {
                    $xml .= $this->inlineCell($ref, $this->safeText($value), $style);
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        $xml .= sprintf('<autoFilter ref="A3:%s%d"/>', $lastColumn, count($rows) + 2);
        if ('A' !== $lastColumn) {
            $xml .= sprintf('<mergeCells count="2"><mergeCell ref="A1:%s1"/><mergeCell ref="A2:%s2"/></mergeCells>', $lastColumn, $lastColumn);
        }
        $xml .= '<printOptions horizontalCentered="1"/>';
        $xml .= '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
        $xml .= '<headerFooter><oddHeader>&amp;C&amp;B'.$this->xml(str_replace('&', '&&', $title)).'&amp;B</oddHeader><oddFooter>&amp;L'.$this->xml(str_replace('&', '&&', $organization)).'&amp;RPage &amp;P / &amp;N</oddFooter></headerFooter>';
        $xml .= '</worksheet>';

        return $xml;
    }

    private function inlineCell(string $reference, string $value, int $style): string
    {
        return sprintf('<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $reference, $style, $this->xml($value));
    }

    /** @param list<list<int|float|string|null>> $rows */
    private function columnWidth(array $rows, int $column): int
    {
        $length = 0;
        foreach (array_slice($rows, 0, 50) as $row) {
            $length = max($length, mb_strlen((string) ($row[$column] ?? '')));
        }

        return max(12, min(46, $length + 3));
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function safeText(int|float|string|null $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@]/', $text) ? "'".$text : $text;
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            --$index;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
    }

    private function workbook(string $title): string
    {
        $name = trim(preg_replace('~[\\\\/?*\[\]:\x00-\x1F]~u', ' ', $title) ?? '', " '");
        $name = mb_substr('' === $name ? 'RiskPilot' : $name, 0, 31);

        return '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$this->xml($name).'" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">'.$this->xml("'".str_replace("'", "''", $name)."'!\$1:\$3").'</definedName></definedNames></workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="10"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="16"/><name val="Aptos Display"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Aptos"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF17324D"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F6E8C"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF2F6"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border/><border><left style="thin"><color rgb="FFD4DEE5"/></left><right style="thin"><color rgb="FFD4DEE5"/></right><top style="thin"><color rgb="FFD4DEE5"/></top><bottom style="thin"><color rgb="FFD4DEE5"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="7"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf></cellXfs></styleSheet>';
    }

    private function appProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>RiskPilot</Application></Properties>';
    }

    private function coreProperties(string $title): string
    {
        $created = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>'.$this->xml($title).'</dc:title><dc:creator>RiskPilot</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.$created.'</dcterms:created></cp:coreProperties>';
    }
}
