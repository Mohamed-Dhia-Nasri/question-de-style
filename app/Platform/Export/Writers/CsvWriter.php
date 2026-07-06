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

        fputcsv($handle, [$document->title]);
        fputcsv($handle, ['Generated at', $document->generatedAt]);

        foreach ($document->filters as $label => $value) {
            fputcsv($handle, ['Filter: '.$label, $value]);
        }

        foreach ($document->disclosures as $line) {
            fputcsv($handle, ['Disclosure', $line]);
        }

        foreach ($document->sections as $section) {
            fputcsv($handle, []);
            fputcsv($handle, [$section['title']]);
            fputcsv($handle, $section['columns']);

            foreach ($section['rows'] as $row) {
                fputcsv($handle, array_map(
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
}
