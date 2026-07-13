<?php

namespace Database\Factories;

use App\Models\User;
use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\ReportBuilder;
use App\Platform\Export\ReportFilters;
use App\Platform\Export\Support\ExportJobStatus;
use App\Shared\Enums\ExportFormat;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic export jobs (no real report content — DP-005 test-data rule).
 *
 * @extends Factory<ExportJob>
 */
class ExportJobFactory extends Factory
{
    use ResolvesTenant;

    protected $model = ExportJob::class;

    public function definition(): array
    {
        $filters = ReportFilters::validate(['grain' => 'month']);

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'user_id' => User::factory(),
            'report' => ReportBuilder::MONITORING_SUMMARY,
            'format' => ExportFormat::Csv,
            'filters' => $filters->toArray(),
            'filters_hash' => $filters->hash(),
            'status' => ExportJobStatus::Pending,
            'correlation_id' => $this->faker->uuid(),
        ];
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => ExportJobStatus::Completed,
            'disk' => 'exports',
            'file_path' => 'exports/test/'.$this->faker->uuid().'.csv',
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }
}
