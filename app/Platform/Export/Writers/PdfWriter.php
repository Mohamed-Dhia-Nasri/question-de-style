<?php

namespace App\Platform\Export\Writers;

use App\Platform\Export\ReportDocument;

/**
 * Minimal native PDF renderer (ENUM-ExportFormat PDF) — a valid PDF 1.4
 * document with base-14 Helvetica fonts and no third-party dependency.
 *
 * Deliberately a tabular text report, not a designed brochure: the
 * binding requirements are fidelity ones — tier labels on every metric,
 * the EMV model + rates disclosure, the filter set, and literal
 * "Unavailable" for absent values (AC-M1-011/012). White-label styled
 * client reports are P4 roadmap work.
 */
class PdfWriter
{
    private const PAGE_WIDTH = 842; // A4 landscape

    private const PAGE_HEIGHT = 595;

    private const MARGIN = 40;

    private const LINE_HEIGHT = 14;

    private const FONT_SIZE = 9;

    private const TITLE_FONT_SIZE = 14;

    public function write(ReportDocument $document): string
    {
        $pages = $this->paginate($this->lines($document));

        // Objects: 1 catalog, 2 pages tree, 3 regular font, 4 bold font,
        // then one page object + one content stream per page.
        $objects = [];
        $pageRefs = [];
        $next = 5;

        foreach ($pages as $lines) {
            $pageId = $next++;
            $contentId = $next++;
            $pageRefs[] = "{$pageId} 0 R";

            $stream = $this->contentStream($lines);
            $objects[$pageId] = "{$pageId} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 "
                .self::PAGE_WIDTH.' '.self::PAGE_HEIGHT."] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>\nendobj\n";
            $objects[$contentId] = "{$contentId} 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream\nendobj\n";
        }

        $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [".implode(' ', $pageRefs).'] /Count '.count($pageRefs)." >>\nendobj\n";
        $objects[3] = "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";
        $objects[4] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    /** @return list<array{text: string, bold: bool}> */
    private function lines(ReportDocument $document): array
    {
        $lines = [
            ['text' => $document->title, 'bold' => true],
            ['text' => 'Generated at '.$document->generatedAt, 'bold' => false],
            ['text' => '', 'bold' => false],
        ];

        foreach ($document->filters as $label => $value) {
            $lines[] = ['text' => 'Filter — '.$label.': '.$value, 'bold' => false];
        }

        foreach ($document->disclosures as $line) {
            $lines[] = ['text' => 'Disclosure — '.$line, 'bold' => false];
        }

        foreach ($document->sections as $section) {
            $lines[] = ['text' => '', 'bold' => false];
            $lines[] = ['text' => $section['title'], 'bold' => true];

            $widths = $this->columnWidths($section['columns'], $section['rows']);
            $lines[] = ['text' => $this->tableRow($section['columns'], $widths), 'bold' => true];

            foreach ($section['rows'] as $row) {
                $cells = array_map(
                    static fn ($cell) => $cell === null ? 'Unavailable' : (string) $cell,
                    $row,
                );
                $lines[] = ['text' => $this->tableRow($cells, $widths), 'bold' => false];
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<string|int|float|null>>  $rows
     * @return list<int>
     */
    private function columnWidths(array $columns, array $rows): array
    {
        $widths = array_map(static fn (string $column): int => mb_strlen($column), $columns);

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $length = mb_strlen($cell === null ? 'Unavailable' : (string) $cell);
                $widths[$i] = max($widths[$i] ?? 0, $length);
            }
        }

        return array_map(static fn (int $width): int => min($width, 34), $widths);
    }

    /**
     * @param  list<string>  $cells
     * @param  list<int>  $widths
     */
    private function tableRow(array $cells, array $widths): string
    {
        $parts = [];

        foreach ($cells as $i => $cell) {
            $width = $widths[$i] ?? 12;
            $text = mb_strlen($cell) > $width ? mb_substr($cell, 0, $width - 1).'…' : $cell;
            $parts[] = str_pad($text, $width);
        }

        return rtrim(implode('  ', $parts));
    }

    /**
     * @param  list<array{text: string, bold: bool}>  $lines
     * @return list<list<array{text: string, bold: bool}>>
     */
    private function paginate(array $lines): array
    {
        $perPage = (int) floor((self::PAGE_HEIGHT - 2 * self::MARGIN) / self::LINE_HEIGHT) - 2;

        return array_chunk($lines, max(1, $perPage));
    }

    /** @param list<array{text: string, bold: bool}> $lines */
    private function contentStream(array $lines): string
    {
        $y = self::PAGE_HEIGHT - self::MARGIN;
        $stream = "BT\n";

        foreach ($lines as $index => $line) {
            $size = $index === 0 && $line['bold'] && $y === self::PAGE_HEIGHT - self::MARGIN
                ? self::TITLE_FONT_SIZE
                : self::FONT_SIZE;
            $font = $line['bold'] ? 'F2' : 'F1';
            $stream .= "/{$font} {$size} Tf\n";
            $stream .= '1 0 0 1 '.self::MARGIN.' '.$y." Tm\n";
            $stream .= '('.$this->escape($line['text']).") Tj\n";
            $y -= self::LINE_HEIGHT;
        }

        return $stream.'ET';
    }

    private function escape(string $text): string
    {
        // Base-14 fonts are WinAnsi — transliterate what we can, never fail.
        $ansi = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);

        if ($ansi === false) {
            $ansi = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ansi);
    }
}
