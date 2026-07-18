<?php

namespace App\Platform\Export\Writers;

use App\Platform\Export\ReportDocument;
use RuntimeException;

/**
 * CSV renderer (ENUM-ExportFormat CSV). Header block carries the filter
 * set and disclosures so tier labels and the EMV model survive outside
 * the application (AC-M1-012).
 */
class CsvWriter
{
    public function write(ReportDocument $document): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open temporary stream for CSV export.');
        }

        $this->writeRow($handle, [$document->title]);
        $this->writeRow($handle, ['Generated at', $document->generatedAt]);

        foreach ($document->filters as $label => $value) {
            $this->writeRow($handle, ['Filter: '.$label, $value]);
        }

        foreach ($document->disclosures as $line) {
            $this->writeRow($handle, ['Disclosure', $line]);
        }

        foreach ($document->sections as $section) {
            fputcsv($handle, []);
            $this->writeRow($handle, [$section['title']]);
            $this->writeRow($handle, $section['columns']);

            foreach ($section['rows'] as $row) {
                $this->writeRow($handle, array_map(
                    static fn ($cell) => $cell === null ? 'Unavailable' : $cell,
                    $row,
                ));
            }
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Write one row with every cell defused against spreadsheet formula
     * injection (CWE-1236). Report cells carry third-party free text
     * (creator display names, handles, cities, brand/product names); a value
     * that opens with =, +, -, @, TAB or CR is executed as a formula when the
     * file is opened in Excel / LibreOffice / Google Sheets.
     *
     * @param  resource  $handle
     * @param  list<string|int|float|null>  $cells
     */
    private function writeRow($handle, array $cells): void
    {
        fputcsv($handle, array_map(self::neutralizeCell(...), $cells));
    }

    /**
     * Prefix a leading single quote to any string cell that begins with a
     * formula trigger character. Non-string cells (int/float metric values,
     * null) pass through untouched, so legitimate negative numbers are never
     * corrupted — only string cells starting with a trigger are neutralized.
     */
    private static function neutralizeCell(mixed $cell): mixed
    {
        if (is_string($cell) && $cell !== '' && str_contains("=+-@\t\r", $cell[0])) {
            return "'".$cell;
        }

        return $cell;
    }
}
