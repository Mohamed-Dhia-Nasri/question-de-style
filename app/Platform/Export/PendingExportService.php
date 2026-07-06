<?php

namespace App\Platform\Export;

use App\Platform\Export\Contracts\ExportService;
use App\Shared\Enums\ExportFormat;
use App\Shared\Exceptions\NotYetImplemented;

class PendingExportService implements ExportService
{
    public function export(ExportFormat $format, array $reportData): string
    {
        throw NotYetImplemented::service('SVC-Export', 'P1');
    }
}
