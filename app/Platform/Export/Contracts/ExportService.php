<?php

namespace App\Platform\Export\Contracts;

use App\Shared\Enums\ExportFormat;

/**
 * SVC-Export (L5) — docs/60-architecture/00-system-architecture.md.
 *
 * Renders reports in the ENUM-ExportFormat formats (PDF/EXCEL/CSV,
 * REQ-M1-012). Reads analytics ROLLUP-* only, never raw facts (ADR-0010).
 * Every metric in an export keeps its tier label; deferred fields render
 * "unavailable", never empty or zero. Exports of personal data must be
 * authorized and audited without recording decrypted values.
 *
 * Implementation is P1 work.
 */
interface ExportService
{
    /**
     * Render the given report payload and return a path/stream reference to
     * the produced artifact.
     *
     * @param  array<string, mixed>  $reportData  pre-aggregated, tier-labelled data
     */
    public function export(ExportFormat $format, array $reportData): string;
}
