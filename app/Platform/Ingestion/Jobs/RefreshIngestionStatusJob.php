<?php

namespace App\Platform\Ingestion\Jobs;

use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Models\ProviderHealthState;
use App\Platform\Ingestion\Observability\AlertService;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\AlertType;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Platform\Ingestion\Support\CycleStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Periodic ingestion-status refresh (requirement: "refreshing ingestion
 * status"): finalizes stale cycles, raises/resolves stale-data warnings
 * per provider, and watches the story-polling deadline (stories expire —
 * a quiet story pipeline is a data-loss risk, REQ-M1-004).
 */
class RefreshIngestionStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(AlertService $alerts): void
    {
        $this->finalizeStaleCycles();
        $this->refreshStaleDataWarnings($alerts);
        $this->watchStoryPollingRisk($alerts);
    }

    private function finalizeStaleCycles(): void
    {
        $staleBefore = CarbonImmutable::now()
            ->subMinutes((int) config('qds.ingestion.cycle_stale_after_minutes'));

        IngestionCycle::query()
            ->where('status', CycleStatus::Running->value)
            ->where('started_at', '<', $staleBefore)
            ->update([
                'status' => CycleStatus::Stale->value,
                'finished_at' => CarbonImmutable::now(),
            ]);
    }

    private function refreshStaleDataWarnings(AlertService $alerts): void
    {
        $staleBefore = CarbonImmutable::now()
            ->subHours((int) config('qds.ingestion.observability.stale_after_hours'));

        foreach (ProviderHealthState::query()->get() as $state) {
            if ($state->last_success_at === null) {
                continue; // never used yet — nothing to be stale
            }

            if ($state->last_success_at->lt($staleBefore)) {
                $alerts->raise(
                    AlertType::StaleData,
                    $state->source,
                    sprintf(
                        '%s has produced no successful call since %s.',
                        $state->source,
                        $state->last_success_at->toIso8601String(),
                    ),
                    'critical',
                );
            } else {
                $alerts->resolve(AlertType::StaleData, $state->source);
            }
        }
    }

    private function watchStoryPollingRisk(AlertService $alerts): void
    {
        $riskHours = (int) config('qds.ingestion.observability.story_polling_risk_hours');
        $riskBefore = CarbonImmutable::now()->subHours($riskHours);

        $lastSuccessfulStoryPoll = ProviderCall::query()
            ->where('source', SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS)
            ->where('operation', 'stories.fetch')
            ->whereIn('outcome', [CallOutcome::Success->value, CallOutcome::Partial->value])
            ->max('started_at');

        if ($lastSuccessfulStoryPoll === null) {
            return; // story polling never ran — covered by cycle enablement, not this alert
        }

        if (CarbonImmutable::parse($lastSuccessfulStoryPoll)->lt($riskBefore)) {
            $alerts->raise(
                AlertType::StoryPollingRisk,
                SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS,
                sprintf(
                    'No successful story poll for over %d hours — stories may expire unarchived (REQ-M1-004).',
                    $riskHours,
                ),
                'critical',
            );
        } else {
            $alerts->resolve(AlertType::StoryPollingRisk, SourceRegistry::APIFY_INSTAGRAM_STORY_DETAILS);
        }
    }
}
