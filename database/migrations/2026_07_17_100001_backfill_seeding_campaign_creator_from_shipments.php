<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F03 repair (2026-07-16 CRM UX audit): shipments referenced creators that
 * were never attached to seeding_campaign_creator (the demo seeder wrote
 * shipments without roster rows), so those shipments failed the
 * recipient-on-roster guard on every re-save and their creators were
 * invisible to the recipient dropdown. Backfill the pivot from existing
 * shipments; insertOrIgnore + the (seeding_campaign_id, creator_id) unique
 * key make this safe to re-run. tenant_id comes from the shipment row —
 * the pivot carries composite tenant FKs (ADR-0019).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $pairs = DB::table('shipments')
            ->select('seeding_campaign_id', 'creator_id', 'tenant_id')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            DB::table('seeding_campaign_creator')->insertOrIgnore([
                'tenant_id' => $pair->tenant_id,
                'seeding_campaign_id' => $pair->seeding_campaign_id,
                'creator_id' => $pair->creator_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Data repair — nothing sensible to undo.
    }
};
