<?php

namespace App\Modules\CRM\Services\Gdpr;

use App\Modules\CRM\Models\Creator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * GDPR data-subject access/portability export (P4 hardening, DP-005): one
 * structured document containing every category of data the platform holds
 * about a creator — CRM profile and contact data, correspondence, campaign
 * participation, shipments, documents, monitored accounts, and the
 * monitoring history collected from their public profiles.
 *
 * Read-only. The output is machine-readable (array → JSON at the command
 * layer) so it satisfies the GDPR portability format requirement.
 */
class CreatorDataExporter
{
    /** @return array<string, mixed> */
    public function export(Creator $creator): array
    {
        $creator->load([
            'contacts',
            'brandPreferences',
            'communicationLogs',
            'platformAccounts',
            'shipments.product',
            'shipments.seedingCampaign',
            'tasks',
            'documentAttachments',
            'campaigns',
            'seedingCampaigns',
            'monitoredSubjects',
        ]);

        $accountIds = $creator->platformAccounts->pluck('id')->all();
        $contentIds = $accountIds === [] ? [] : DB::table('content_items')
            ->whereIn('platform_account_id', $accountIds)->pluck('id')->all();

        return [
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'data_subject' => [
                'creator_id' => $creator->id,
                'display_name' => $creator->display_name,
                'primary_language' => $creator->primary_language,
                'relationship_status' => $creator->relationship_status?->value,
                'created_at' => $creator->created_at->toIso8601String(),
            ],
            'contacts' => $creator->contacts->map(fn ($c) => [
                'email' => $c->email,
                'phone' => $c->phone,
                'postal_address' => $c->postal_address,
                'preferred_channel' => $c->preferred_channel,
            ])->all(),
            'brand_preferences' => $creator->brandPreferences->map(fn ($p) => [
                'preferred_brands' => $p->preferred_brands,
                'restricted_brands' => $p->restricted_brands,
                'notes' => $p->notes,
            ])->all(),
            'communication_logs' => $creator->communicationLogs->map(fn ($log) => [
                'channel' => $log->channel,
                'direction' => $log->direction,
                'summary' => $log->summary,
                'occurred_at' => $log->occurred_at->toIso8601String(),
            ])->all(),
            'platform_accounts' => $creator->platformAccounts->map(fn ($a) => [
                'platform' => $a->platform->value,
                'handle' => $a->handle,
                'bio' => $a->bio,
                'external_links' => $a->external_links,
                'follower_count' => $a->follower_count?->toArray(),
            ])->all(),
            'campaign_participation' => [
                'campaigns' => $creator->campaigns->pluck('name')->all(),
                'seeding_campaigns' => $creator->seedingCampaigns->pluck('name')->all(),
            ],
            'shipments' => $creator->shipments->map(fn ($s) => [
                'product' => $s->product?->name,
                'seeding_campaign' => $s->seedingCampaign?->name,
                'status' => $s->status->value,
                'tracking_number' => $s->tracking_number,
                'shipped_at' => $s->shipped_at?->toIso8601String(),
                'delivered_at' => $s->delivered_at?->toIso8601String(),
            ])->all(),
            'tasks' => $creator->tasks->map(fn ($t) => [
                'title' => $t->title,
                'due_at' => $t->due_at?->toIso8601String(),
                'status' => $t->status->value,
            ])->all(),
            'documents' => $creator->documentAttachments->map(fn ($d) => [
                'file_name' => $d->file_name,
                'uploaded_at' => $d->uploaded_at->toIso8601String(),
            ])->all(),
            'monitoring' => [
                'roster_entries' => $creator->monitoredSubjects->map(fn ($s) => [
                    'label' => $s->label,
                    'active' => (bool) $s->active,
                ])->all(),
                'content_items' => $accountIds === [] ? [] : DB::table('content_items')
                    ->whereIn('platform_account_id', $accountIds)
                    ->get(['platform', 'external_id', 'permalink', 'caption', 'published_at'])
                    ->map(fn ($row) => (array) $row)
                    ->all(),
                'stories' => $accountIds === [] ? [] : DB::table('stories')
                    ->whereIn('platform_account_id', $accountIds)
                    ->get(['platform', 'external_id', 'captured_at', 'expires_at'])
                    ->map(fn ($row) => (array) $row)
                    ->all(),
                'metric_snapshot_count' => ($accountIds === [] && $contentIds === []) ? 0 : DB::table('metric_snapshots')
                    ->where(fn ($q) => $q->whereIn('platform_account_id', $accountIds)->orWhereIn('content_item_id', $contentIds))
                    ->count(),
            ],
        ];
    }
}
