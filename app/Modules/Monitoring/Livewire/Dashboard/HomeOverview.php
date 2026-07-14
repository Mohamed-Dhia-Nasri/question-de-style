<?php

namespace App\Modules\Monitoring\Livewire\Dashboard;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Analytics\RollupReader;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\SeedingCampaignStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Home dashboard tiles — replaces the static P0 placeholder page now that
 * Monitoring (P1) and CRM & Seeding (P3) are live. Entity counts come from
 * the owning models; the KPI aggregate (estimated reach) comes from the
 * approved rollups only (ADR-0010) and is tier-labelled ESTIMATED per
 * ADR-0022 when an active reach configuration exists (else unavailable,
 * DP-001). CONFIRMED unique reach stays deferred (DEF-003) and is never
 * fabricated.
 *
 * Reached via /dashboard, which is gated on INTERNAL_ACCESS — every staff
 * role also holds monitoring.view and crm.view (PermissionsCatalog), so
 * these tiles reveal nothing a staff viewer could not already open;
 * CLIENT_VIEWER never reaches this route (ADR-0016 containment).
 */
class HomeOverview extends Component
{
    public function render(RollupReader $rollups): View
    {
        $since = Carbon::now()->subDays(30);

        $rosterCount = MonitoredSubject::query()
            ->where('subject_type', MonitoredSubjectType::Creator->value)
            ->where('active', true)
            ->count();

        $mentions30d = Mention::query()
            ->where('created_at', '>=', $since)
            ->count();

        $activeCampaigns = Campaign::query()
            ->where('status', CampaignStatus::Active->value)
            ->count();

        $activeSeedingRuns = SeedingCampaign::query()
            ->whereIn('status', [
                SeedingCampaignStatus::Active->value,
                SeedingCampaignStatus::Shipping->value,
            ])
            ->count();

        $mentionTotals = $rollups->mentionTotals($since);

        $recentContent = ContentItem::query()
            ->with('platformAccount.creator')
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->limit(8)
            ->get();

        return view('livewire.monitoring.home-overview', [
            'rosterCount' => $rosterCount,
            'mentions30d' => $mentions30d,
            'activeCampaigns' => $activeCampaigns,
            'activeSeedingRuns' => $activeSeedingRuns,
            'estimatedReach30d' => $mentionTotals->total_estimated_reach,
            'recentContent' => $recentContent,
            'rollupsRefreshedAt' => $rollups->lastRefreshedAt(),
        ]);
    }
}
