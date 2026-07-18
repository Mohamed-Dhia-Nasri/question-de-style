<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            // NOTE: column is `mentioned_handles`, NOT `mentions` — a `mentions`
            // cast would shadow the existing ContentItem::mentions() HasMany
            // relationship (attribution Mention rows) and break the dashboard.
            $table->jsonb('mentioned_handles')->nullable()->after('caption');
            $table->jsonb('product_tags')->nullable()->after('mentioned_handles');
            $table->jsonb('collaborators')->nullable()->after('product_tags');
            // Tri-state: true=paid, false=explicitly-not, null=unknown. No default.
            $table->boolean('branded_content_label')->nullable()->after('collaborators');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn(['mentioned_handles', 'product_tags', 'collaborators', 'branded_content_label']);
        });
    }
};
