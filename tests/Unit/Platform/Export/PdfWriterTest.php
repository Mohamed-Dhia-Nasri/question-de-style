<?php

namespace Tests\Unit\Platform\Export;

use App\Platform\Export\ReportDocument;
use App\Platform\Export\Writers\PdfWriter;
use PHPUnit\Framework\TestCase;

/**
 * PDF horizontal fit (M20). A wide (13-column) seeding section must stay
 * within the A4-landscape printable area (842 - 2*40 = 762pt) — otherwise the
 * right-hand columns run off the page and are lost.
 */
class PdfWriterTest extends TestCase
{
    public function test_wide_table_rows_stay_within_the_printable_area(): void
    {
        // Plain ASCII so the PDF escape pass is identity and char counts are exact.
        $columns = [
            'Creator', 'Creator country', 'Creator city', 'Product', 'Variant',
            'Shipped at', 'Delivered at', 'Status', 'Tracking', 'Quantity',
            'Value CONFIRMED', 'Estimated reach ESTIMATED', 'EMV ESTIMATED',
        ];
        $row = array_map(static fn (): string => str_repeat('X', 34), $columns);

        $document = new ReportDocument(
            title: 'Wide',
            generatedAt: 'now',
            filters: [],
            disclosures: [],
            sections: [[
                'title' => 'Seeding results by shipment',
                'columns' => $columns,
                'rows' => [$row, $row],
            ]],
        );

        $pdf = (new PdfWriter)->write($document);

        // Each emitted text line: /F{n} {size} Tf \n 1 0 0 1 {x} {y} Tm \n ({text}) Tj
        preg_match_all(
            '/\/F\d+\s+(\d+)\s+Tf\s+1 0 0 1 (\d+) [\-\d]+ Tm\s+\((.*?)\) Tj/',
            $pdf,
            $matches,
            PREG_SET_ORDER,
        );

        $this->assertNotEmpty($matches, 'no text was emitted into the PDF content stream');

        $printableWidth = 842 - 2 * 40; // 762pt
        $maxWidthPt = 0.0;

        foreach ($matches as $match) {
            $size = (int) $match[1];
            $startX = (int) $match[2];
            $chars = mb_strlen($match[3]);

            // Every line starts at the left margin…
            $this->assertSame(40, $startX);

            // …and its conservative Helvetica width must end before the right margin.
            $maxWidthPt = max($maxWidthPt, $chars * $size * 0.6);
        }

        $this->assertLessThanOrEqual(
            $printableWidth,
            $maxWidthPt,
            'PDF table row runs off the A4-landscape printable area (M20)',
        );
    }
}
