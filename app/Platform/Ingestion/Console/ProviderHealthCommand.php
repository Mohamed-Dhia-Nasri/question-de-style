<?php

namespace App\Platform\Ingestion\Console;

use App\Platform\Ingestion\Observability\ProviderHealthService;
use Illuminate\Console\Command;

/**
 * Operator view of per-provider health (External API Monitoring): status,
 * last success/failure, success rate, durations, invalid-response rate,
 * and stale-data warnings.
 */
class ProviderHealthCommand extends Command
{
    protected $signature = 'qds:provider-health';

    protected $description = 'Show the current health of every SRC-* provider';

    public function handle(ProviderHealthService $health): int
    {
        $rows = [];

        foreach ($health->overview() as $source => $view) {
            $rows[] = [
                $source,
                $view['status'],
                $view['last_success_at'] ?? '—',
                $view['consecutive_failures'],
                $view['success_rate'] !== null ? number_format($view['success_rate'] * 100, 1).'%' : '—',
                $view['avg_duration_ms'] !== null ? $view['avg_duration_ms'].' ms' : '—',
                $view['p95_duration_ms'] !== null ? $view['p95_duration_ms'].' ms' : '—',
                $view['invalid_response_rate'] !== null ? number_format($view['invalid_response_rate'] * 100, 1).'%' : '—',
                $view['stale_data_warning'] ? 'STALE' : 'ok',
            ];
        }

        $this->table(
            ['Source', 'Status', 'Last success', 'Consec. failures', 'Success rate', 'Avg', 'p95', 'Invalid rate', 'Freshness'],
            $rows,
        );

        return self::SUCCESS;
    }
}
