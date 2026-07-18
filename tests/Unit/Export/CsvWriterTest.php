<?php

namespace Tests\Unit\Export;

use App\Platform\Export\ReportDocument;
use App\Platform\Export\Writers\CsvWriter;
use PHPUnit\Framework\TestCase;

/**
 * CsvWriter output-encoding guarantees (M17 / M01).
 *
 * Report cells carry third-party free text — creator display names, social
 * handles, cities, brand/product names. A cell that begins with =, +, -, @,
 * TAB or CR is a spreadsheet formula when the file is opened in Excel /
 * LibreOffice / Google Sheets (CWE-1236). The writer is the single CSV sink,
 * so it must neutralize every such cell before fputcsv.
 */
class CsvWriterTest extends TestCase
{
    public function test_formula_prefixed_cells_are_neutralized_across_every_field(): void
    {
        $document = new ReportDocument(
            title: 'Report',
            generatedAt: '2026-07-18',
            filters: ['Creator' => '=cmd|calc'],
            disclosures: ['-hello'],
            sections: [[
                'title' => 'Creators',
                'columns' => ['Name'],
                'rows' => [['=1+1'], ['@SUM(A1)'], ['+41'], ['-41'], ["\tTAB"]],
            ]],
        );

        $csv = (new CsvWriter)->write($document);

        // Each dangerous leading char must be defused with a leading single quote.
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("'@SUM(A1)", $csv);
        $this->assertStringContainsString("'+41", $csv);
        $this->assertStringContainsString("'-41", $csv);
        $this->assertStringContainsString("'=cmd|calc", $csv);   // filter value too
        $this->assertStringContainsString("'-hello", $csv);      // disclosure too

        // A raw, un-neutralized formula must NOT survive anywhere.
        $this->assertStringNotContainsString("\n=1+1", $csv);
    }

    public function test_benign_cells_are_left_untouched(): void
    {
        $document = new ReportDocument(
            title: 'Report',
            generatedAt: '2026-07-18',
            filters: ['Platform' => 'INSTAGRAM'],
            disclosures: ['[PUBLIC]'],
            sections: [[
                'title' => 'Creators',
                'columns' => ['Name', 'Views'],
                'rows' => [['nike', 1200], ['Acme Co', null]],
            ]],
        );

        $csv = (new CsvWriter)->write($document);

        $this->assertStringContainsString('nike', $csv);
        $this->assertStringNotContainsString("'nike", $csv);
        $this->assertStringContainsString('1200', $csv);
        $this->assertStringContainsString('Unavailable', $csv);   // null preserved, not 0
    }
}
