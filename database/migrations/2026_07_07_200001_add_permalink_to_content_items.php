<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical public page URL of a content item (the post/reel/video page,
 * NOT a CDN media URL — those live in media_urls and expire). Needed by the
 * campaign-linked metric refresh (qds.ingestion.campaign_refresh): the
 * general Instagram actor re-fetches metrics for specific posts via their
 * direct URLs, so the URL must be stored at ingestion time. Nullable —
 * provider payloads do not always carry it, and rows ingested before this
 * column exist without one until next re-poll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('permalink', 2048)->nullable()->after('media_urls');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn('permalink');
        });
    }
};
