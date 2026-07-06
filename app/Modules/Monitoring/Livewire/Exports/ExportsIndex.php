<?php

namespace App\Modules\Monitoring\Livewire\Exports;

use App\Models\User;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use App\Platform\Analytics\RollupReader;
use App\Platform\Export\ExportManager;
use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\ReportBuilder;
use App\Shared\Enums\ExportFormat;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Report exports (REQ-M1-012, AC-M1-012): request a rollup-backed report
 * in PDF / EXCEL / CSV with the same validated server-side filters the
 * dashboards use, then download it through a short-lived signed link.
 *
 * Server-side authorization on every action (exports.create via
 * ExportJobPolicy); duplicate requests collapse onto the live job; the
 * listing shows only the requester's own exports.
 */
class ExportsIndex extends Component
{
    use WithPagination;

    public string $format = 'CSV';

    public string $grain = 'month';

    public string $from = '';

    public string $to = '';

    public int $brandId = 0;

    public int $creatorId = 0;

    public function mount(): void
    {
        $this->authorize('viewAny', ExportJob::class);
    }

    public function requestExport(ExportManager $exports): void
    {
        $this->authorize('create', ExportJob::class);

        $format = ExportFormat::tryFrom($this->format);

        if ($format === null) {
            $this->addError('format', 'Unknown export format.');

            return;
        }

        /** @var User $user */
        $user = Auth::user();

        try {
            $exports->request($user, ReportBuilder::MONITORING_SUMMARY, $format, [
                'grain' => $this->grain,
                'from' => $this->from !== '' ? $this->from : null,
                'to' => $this->to !== '' ? $this->to : null,
                'brand_id' => $this->brandId > 0 ? $this->brandId : null,
                'creator_id' => $this->creatorId > 0 ? $this->creatorId : null,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, implode(' ', $messages));
            }

            return;
        }

        $this->resetPage();
        $this->dispatch('notify', type: 'success', message: 'Export requested — it will appear below when ready.');
    }

    public function download(int $exportJobId, ExportManager $exports): void
    {
        $job = ExportJob::query()->findOrFail($exportJobId);

        $this->authorize('download', $job);

        if (! $job->isDownloadable()) {
            $this->dispatch('notify', type: 'error', message: 'This export is not downloadable (pending, failed, or expired).');

            return;
        }

        // Short-lived signed URL; the browser follows it once.
        $this->redirect($exports->downloadUrl($job));
    }

    public function render(RollupReader $rollups): View
    {
        return view('livewire.monitoring.exports-index', [
            'jobs' => ExportJob::query()
                ->where('user_id', Auth::id())
                ->orderByDesc('id')
                ->paginate(10),
            'formats' => ExportFormat::cases(),
            'grains' => RollupReader::GRAINS,
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'creators' => Creator::query()->orderBy('display_name')->get(['id', 'display_name']),
            'rollupsRefreshedAt' => $rollups->lastRefreshedAt(),
        ]);
    }
}
