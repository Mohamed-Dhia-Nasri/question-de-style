<?php

namespace App\Platform\Export\Writers;

use App\Platform\Export\ReportDocument;
use RuntimeException;
use ZipArchive;

/**
 * Minimal native XLSX renderer (ENUM-ExportFormat EXCEL) — SpreadsheetML
 * with inline strings, zero third-party dependencies (the frozen-stack
 * discipline extends to not pulling an export library without a decision).
 *
 * Layout: sheet 1 is the report header (title, filters, disclosures);
 * each report section becomes its own worksheet. Numeric cells stay
 * numeric; NULL renders as the literal "Unavailable" (never blank/zero).
 */
class XlsxWriter
{
    public function write(ReportDocument $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qds-xlsx-');

        if ($path === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the Excel export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open the Excel archive for writing.');
        }

        $sheets = $this->sheets($document);

        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
            </Relationships>
            XML);
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels(count($sheets)));

        foreach ($sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->worksheet($sheet['rows']));
        }

        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** @return list<array{name: string, rows: list<list<string|int|float|null>>}> */
    private function sheets(ReportDocument $document): array
    {
        $header = [[$document->title], ['Generated at', $document->generatedAt]];

        foreach ($document->filters as $label => $value) {
            $header[] = ['Filter: '.$label, $value];
        }

        foreach ($document->disclosures as $line) {
            $header[] = ['Disclosure', $line];
        }

        $sheets = [['name' => 'Report', 'rows' => $header]];

        foreach ($document->sections as $index => $section) {
            $sheets[] = [
                'name' => $this->sheetName($section['title'], $index),
                'rows' => [$section['columns'], ...$section['rows']],
            ];
        }

        return $sheets;
    }

    private function sheetName(string $title, int $index): string
    {
        $name = preg_replace('/[\\\\\/*?:\[\]]/', ' ', $title) ?? 'Section';
        $name = trim(mb_substr($name, 0, 28));

        return $name === '' ? 'Section '.($index + 2) : $name;
    }

    private function contentTypes(int $sheetCount): string
    {
        $overrides = '';

        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'</Types>';
    }

    /** @param list<array{name: string, rows: mixed}> $sheets */
    private function workbook(array $sheets): string
    {
        $entries = '';

        foreach ($sheets as $index => $sheet) {
            $id = $index + 1;
            $entries .= '<sheet name="'.htmlspecialchars($sheet['name'], ENT_XML1).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$entries.'</sheets>'
            .'</workbook>';
    }

    private function workbookRels(int $sheetCount): string
    {
        $entries = '';

        for ($i = 1; $i <= $sheetCount; $i++) {
            $entries .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$entries.'</Relationships>';
    }

    /** @param list<list<string|int|float|null>> $rows */
    private function worksheet(array $rows): string
    {
        $xmlRows = '';

        foreach ($rows as $row) {
            $cells = '';

            foreach ($row as $cell) {
                if ($cell === null) {
                    $cell = 'Unavailable';
                }

                if (is_int($cell) || is_float($cell)) {
                    $cells .= '<c t="n"><v>'.$cell.'</v></c>';
                } else {
                    $cells .= '<c t="inlineStr"><is><t xml:space="preserve">'
                        .htmlspecialchars($cell, ENT_XML1)
                        .'</t></is></c>';
                }
            }

            $xmlRows .= '<row>'.$cells.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$xmlRows.'</sheetData>'
            .'</worksheet>';
    }
}
