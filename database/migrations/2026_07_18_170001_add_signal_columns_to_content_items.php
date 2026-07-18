<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->jsonb('mentions')->nullable()->after('caption');
            $table->jsonb('product_tags')->nullable()->after('mentions');
            $table->jsonb('collaborators')->nullable()->after('product_tags');
            // Tri-state: true=paid, false=explicitly-not, null=unknown. No default.
            $table->boolean('branded_content_label')->nullable()->after('collaborators');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn(['mentions', 'product_tags', 'collaborators', 'branded_content_label']);
        });
    }
};
