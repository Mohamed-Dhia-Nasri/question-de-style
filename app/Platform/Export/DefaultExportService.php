<?php

namespace App\Platform\Export;

use App\Platform\Export\Contracts\ExportService;
use App\Platform\Export\Writers\CsvWriter;
use App\Platform\Export\Writers\PdfWriter;
use App\Platform\Export\Writers\XlsxWriter;
use App\Shared\Enums\ExportFormat;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SVC-Export (L5) — renders a rollup-backed ReportDocument into one
 * ENUM-ExportFormat artifact on the PRIVATE exports disk (REQ-M1-012).
 *
 * The stored name is random (no user input, no personal data, no
 * guessable sequence); access goes exclusively through the signed
 * download route after an ExportJobPolicy check — never a public URL.
 */
class DefaultExportService implements ExportService
{
    public function __construct(
        private readonly CsvWriter $csv,
        private readonly XlsxWriter $xlsx,
        private readonly PdfWriter $pdf,
    ) {}

    public function export(ExportFormat $format, array $reportData): string
    {
        $document = ReportDocument::fromArray($reportData);

        $bytes = match ($format) {
            ExportFormat::Csv => $this->csv->write($document),
            ExportFormat::Excel => $this->xlsx->write($document),
            ExportFormat::Pdf => $this->pdf->write($document),
        };

        $path = 'exports/'.now()->format('Y/m').'/'.Str::uuid().'.'.self::extension($format);

        Storage::disk(self::disk())->put($path, $bytes);

        return $path;
    }

    public static function disk(): string
    {
        return (string) config('qds.exports.disk', 'exports');
    }

    public static function extension(ExportFormat $format): string
    {
        return match ($format) {
            ExportFormat::Csv => 'csv',
            ExportFormat::Excel => 'xlsx',
            ExportFormat::Pdf => 'pdf',
        };
    }
}
